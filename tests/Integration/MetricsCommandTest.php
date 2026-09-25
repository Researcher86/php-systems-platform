<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration;

use PhpJobQueue\Metrics\MetricsCollector;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Producer\JobFactory;
use PhpJobQueue\Producer\Producer;
use PhpJobQueue\Queue\InMemoryQueue;
use PhpJobQueue\Support\SystemClock;
use PhpSystemsPlatform\Queue\Jobs\NoopJob;
use PHPUnit\Framework\TestCase;

/**
 * `metrics` prints PLAN Step 23's standard metric snapshot the same way
 * GET /metrics does, without a serve behind it: the journal's queue.* lines
 * and this process's RSS are always readable, and the worker pool is only
 * consulted when one answers. Like the other demo/status commands the real
 * CLI runs as a subprocess and reads the same config data dir the serve
 * integration tests use.
 */
final class MetricsCommandTest extends TestCase
{
    private const QUEUE_LOG = '/tmp/php-systems-platform/queue/queue.log';

    public function testPrintsTheStandardSnapshotFromTheJournalAndRss(): void
    {
        $this->prepareJournal(2);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'metrics'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        @unlink(self::QUEUE_LOG);

        self::assertSame(0, $code, $errors . $output);

        $lines = array_values(array_filter(preg_split('/\R/', trim((string) $output))));
        self::assertNotSame([], $lines);

        $metrics = [];

        foreach ($lines as $line) {
            [$name, $value] = explode(' ', $line, 2);
            $metrics[$name] = $value;
        }

        // Plain text, one `name value` per line, sorted by name.
        $names = array_keys($metrics);
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);

        // The two bench.noop jobs published below: depth 2, nothing terminal.
        self::assertSame('2', $metrics['queue.published']);
        self::assertSame('2', $metrics['queue.depth']);
        self::assertSame('0', $metrics['queue.completed']);
        self::assertSame('0', $metrics['queue.failed']);
        self::assertSame('0', $metrics['queue.retried']);

        // This process's RSS is always measurable without any infra.
        self::assertArrayHasKey('process.rss', $metrics);
        self::assertGreaterThan(0, (int) $metrics['process.rss']);
    }

    private function prepareJournal(int $count): void
    {
        $dir = dirname(self::QUEUE_LOG);

        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            self::fail('Could not create the queue data dir for the metrics test.');
        }

        @unlink(self::QUEUE_LOG);

        $clock = new SystemClock();
        $producer = new Producer(
            new InMemoryQueue($clock, new FileStorage(self::QUEUE_LOG)),
            new JobFactory($clock, new MetricsCollector()),
        );

        for ($i = 0; $i < $count; $i++) {
            $producer->dispatch(NoopJob::TYPE);
        }
    }
}
