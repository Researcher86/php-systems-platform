<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpMiniDatabase\Client\ClientConfig;
use PhpSystemsPlatform\Domain\Customer;
use PhpSystemsPlatform\Domain\OrderLoader;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\OrderSnapshot;
use PhpSystemsPlatform\Domain\Product;
use PhpSystemsPlatform\Domain\StockLevel;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\CatalogRepository;
use RuntimeException;
use Throwable;

/**
 * The third way to build the same snapshot, and the one place in the
 * platform where the concurrency primitive is not borrowed from a component
 * (PLAN Step 14): this class forks the processes itself.
 *
 * The mechanism, exactly as php-concurrency teaches it and php-worker-pool
 * uses it internally:
 *
 *   socketpair()  - a connected pair of local sockets, created BEFORE the
 *                   fork so both processes inherit both ends
 *   pcntl_fork()  - the same code now runs in two processes; only the return
 *                   value tells them apart (child: 0, parent: the child pid)
 *   close the end you do not own - otherwise the parent keeps a reference to
 *                   the child's write end and its reads never see EOF
 *   pcntl_waitpid() - the parent reaps every child, or they linger as zombies
 *
 * One child per independent part, so the three reads wait at the same time.
 * What this costs, and what the pool exists to avoid, is visible in
 * `orders:compare`: every load pays three process creations and three fresh
 * database connections, while the pool pays them once and keeps the
 * processes. Same overlap, different amortization - that is the lesson, and
 * the reason the platform's real paths use the pool.
 *
 * A child must build its own database connection: fork() duplicates the
 * parent's socket, and two processes answering on one connection is how a
 * protocol dies. It must also drop the parent's signal handlers - `serve`
 * and `queue:consume` install shutdown handlers over their own state, and a
 * child inheriting them would run the parent's shutdown from the wrong
 * process.
 */
final readonly class ForkedOrderLoader implements OrderLoader
{
    /**
     * @param array<string, mixed> $databaseConfig the `database` config block,
     *                                             for the connection each
     *                                             child opens for itself
     */
    public function __construct(
        private OrderService $orders,
        private array $databaseConfig,
        private int $simulatedLatencyMs = 0,
    ) {
    }

    public function load(string $id): ?OrderSnapshot
    {
        $order = $this->orders->getOrder($id);

        if ($order === null) {
            return null;
        }

        // The order is the dependency; these three are independent of each
        // other, which is the only reason they may run side by side.
        $rows = $this->readInParallel([
            'customer' => fn (CatalogRepository $catalog): ?object => $catalog->findCustomer($order->customer),
            'product' => fn (CatalogRepository $catalog): ?object => $catalog->findProduct($order->product),
            'stock' => fn (CatalogRepository $catalog): ?object => $catalog->findStock($order->product),
        ]);

        return new OrderSnapshot(
            order: $order,
            customer: $rows['customer'] === null ? null : Customer::fromRow($rows['customer']),
            product: $rows['product'] === null ? null : Product::fromRow($rows['product']),
            stock: $rows['stock'] === null ? null : StockLevel::fromRow($rows['stock']),
        );
    }

    /**
     * Run every reader in its own process and collect what each one found.
     *
     * @param array<string, \Closure(CatalogRepository): ?object> $readers
     *
     * @return array<string, array<string, mixed>|null> the row each reader
     *                                                  found, keyed like $readers
     */
    private function readInParallel(array $readers): array
    {
        /** @var array<string, array{int, resource}> $children pid and the parent's end, per part */
        $children = [];

        foreach ($readers as $part => $reader) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($pair === false) {
                $this->reap($children);

                throw new RuntimeException(sprintf('Could not create a socket pair for the "%s" read.', $part));
            }

            [$parentEnd, $childEnd] = $pair;
            $pid = pcntl_fork();

            if ($pid === -1) {
                fclose($parentEnd);
                fclose($childEnd);
                $this->reap($children);

                throw new RuntimeException(sprintf('Could not fork for the "%s" read.', $part));
            }

            if ($pid === 0) {
                fclose($parentEnd);
                $this->readInChild($reader, $childEnd);
            }

            fclose($childEnd);
            $children[$part] = [$pid, $parentEnd];
        }

        $rows = [];

        foreach ($children as $part => [$pid, $parentEnd]) {
            $answer = (string) stream_get_contents($parentEnd);
            fclose($parentEnd);
            pcntl_waitpid($pid, $status);

            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException(sprintf('The "%s" read did not complete: %s', $part, $answer));
            }

            $decoded = json_decode($answer, true);

            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf('The "%s" read answered nothing usable.', $part));
            }

            $row = $decoded['row'] ?? null;
            $rows[$part] = is_array($row) ? $row : null;
        }

        return $rows;
    }

    /**
     * The child half of the fork: own connection, one read, one answer, and
     * an exit that never returns into the caller's code.
     *
     * @param \Closure(CatalogRepository): ?object $reader
     * @param resource                             $socket
     */
    private function readInChild(\Closure $reader, mixed $socket): never
    {
        foreach ([SIGINT, SIGTERM, SIGCHLD] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        $database = null;

        try {
            $database = Database::fromConfig(new ClientConfig(
                host: (string) $this->databaseConfig['host'],
                port: (int) $this->databaseConfig['port'],
                connectTimeoutSeconds: (float) $this->databaseConfig['timeout'],
            ));

            $found = $reader(new CatalogRepository($database));

            if ($this->simulatedLatencyMs > 0) {
                usleep($this->simulatedLatencyMs * 1000);
            }

            fwrite($socket, (string) json_encode([
                'row' => $found === null ? null : get_object_vars($found),
            ]));
            $exitCode = 0;
        } catch (Throwable $e) {
            // The parent reads the message and the exit code, so a failed
            // child is a failed part rather than a silent empty one.
            fwrite($socket, $e->getMessage());
            $exitCode = 1;
        }

        $database?->close();
        fclose($socket);

        exit($exitCode);
    }

    /**
     * Wait for children already forked when a later fork failed - a partial
     * fan-out must not leave processes behind.
     *
     * @param array<string, array{int, resource}> $children
     */
    private function reap(array $children): void
    {
        foreach ($children as [$pid, $parentEnd]) {
            fclose($parentEnd);
            pcntl_waitpid($pid, $status);
        }
    }
}
