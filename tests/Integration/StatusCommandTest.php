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
 * `status` prints PLAN Step 25's whole-platform view: every component's
 * state and headline numbers in one place. Like the other read commands it
 * runs as a subprocess against the same config the serve integration tests
 * use; the assertions here cover the parts that hold no matter what other
 * infrastructure happens to be up - the section layout, the journal-backed
 * Queue section, and the serve's /metrics being the only home of the
 * HTTP/cache/database counters (0 when no serve is answering).
 */
final class StatusCommandTest extends TestCase
{
    private const QUEUE_LOG = '/tmp/php-systems-platform/queue/queue.log';

    public function testPrintsEveryPlatformSection(): void
    {
        $output = $this->runStatus();

        foreach (['HTTP Server', 'Cache', 'Database', 'Queue', 'Workers', 'Memory'] as $section) {
            self::assertStringContainsString($section, $output);
        }

        self::assertStringContainsString('master RSS:', $output);
        self::assertStringContainsString('workers RSS:', $output);

        // Every component answers running or stopped - never anything else.
        self::assertMatchesRegularExpression('/^  status:\s+(running|stopped)$/m', $output);

        // And a counter that only lives inside a serve prints as a number
        // either way; without a serve it is 0, not an error.
        self::assertMatchesRegularExpression('/^  requests:\s+\d/m', $output);
    }

    public function testQueueCountersComeFromTheJournal(): void
    {
        $this->prepareJournal(2);

        try {
            $output = $this->runStatus();

            self::assertSame(1, preg_match('/^  depth:\s+(.+)$/m', $output, $matches));
            self::assertSame('2', $matches[1]);
            self::assertSame(1, preg_match('/^  processed:\s+(.+)$/m', $output, $matches));
            self::assertSame('0', $matches[1]);
            self::assertSame(1, preg_match('/^  failed:\s+(.+)$/m', $output, $matches));
            self::assertSame('0', $matches[1]);
        } finally {
            @unlink(self::QUEUE_LOG);
        }
    }

    private function runStatus(): string
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/platform.php', 'status'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, $errors . $output);

        return (string) $output;
    }

    private function prepareJournal(int $count): void
    {
        $dir = dirname(self::QUEUE_LOG);

        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            self::fail('Could not create the queue data dir for the status test.');
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
