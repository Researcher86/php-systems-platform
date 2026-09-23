<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Storage\Repositories;

use PhpSystemsPlatform\Domain\Order;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Domain\OrderStatus;
use PhpSystemsPlatform\Storage\Database;

/**
 * Persistence for orders - the only place in the platform that writes SQL
 * about them. Kept deliberately small: map a domain Order to/from one row,
 * nothing more.
 */
final readonly class OrderRepository
{
    private const COLUMNS = 'id, customer, amount, product, status, created_at, updated_at';

    public function __construct(
        private Database $database,
    ) {
    }

    public function create(Order $order): bool
    {
        $affected = $this->database->write(
            'INSERT INTO orders (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $order->id,
                $order->customer,
                $order->amount,
                $order->product,
                $order->status->value,
                $order->createdAt,
                $order->updatedAt,
            ],
        );

        return $affected === 1;
    }

    public function find(string $id): ?Order
    {
        $rows = $this->database->read('SELECT ' . self::COLUMNS . ' FROM orders WHERE id = ?', [$id]);

        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /** Newest first, so a list is naturally a chronological reverse.
     *
     * @return list<Order>
     */
    public function all(): array
    {
        $orders = [];

        foreach ($this->database->read('SELECT ' . self::COLUMNS . ' FROM orders ORDER BY created_at DESC') as $row) {
            $orders[] = $this->hydrate($row);
        }

        return $orders;
    }

    public function updateStatus(string $id, OrderStatus $status, string $updatedAt): bool
    {
        $affected = $this->database->write(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            [$status->value, $updatedAt, $id],
        );

        return $affected === 1;
    }

    public function delete(string $id): bool
    {
        $affected = $this->database->write('DELETE FROM orders WHERE id = ?', [$id]);

        return $affected === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Order
    {
        return new Order(
            id: (string) $row['id'],
            customer: (string) $row['customer'],
            amount: (string) $row['amount'],
            // A row written before the product column existed reads as null;
            // the default sku keeps such an order loadable instead of fatal.
            product: ((string) ($row['product'] ?? '')) ?: OrderService::DEFAULT_PRODUCT,
            status: OrderStatus::from((string) $row['status']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
