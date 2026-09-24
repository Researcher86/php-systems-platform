<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use Closure;
use PhpJobQueue\Dispatcher\JobDispatcher;
use PhpJobQueue\Job\Job;
use PhpJobQueue\Job\JobState;
use PhpJobQueue\Persistence\FileStorage;
use PhpJobQueue\Queue\Queue as ComponentQueue;
use PhpJobQueue\Support\Clock;
use PhpJobQueue\Support\SystemClock;
use PhpSystemsPlatform\Workers\WorkerRegistry;

/**
 * The platform's long-running queue consumer loop.
 *
 * The component's QueueRuntime runs one process that owns the queue and
 * dispatches from it; the platform splits that across processes - serve
 * owns the producer, queue:consume owns the consumer, and the append-only
 * journal is the only shared state. A consumer therefore cannot just
 * restore once and run: jobs published by another process while it lives
 * must still be picked up. This loop keeps the component's tick shape
 * (dispatch pending, apply every answer, requeue expired) but re-reads the
 * journal on a short interval and pushes any row it has not seen before,
 * so a producer and a consumer run side by side and the consumer drains
 * what the producer publishes.
 *
 * The shutdown contract is QueueRuntime's too: SIGTERM and SIGINT only set
 * a flag, the loop notices it on its next pass, and workers get the
 * configured grace period on the loop's own thread. One-shot, like the
 * runtime: a consumer that has stopped stays stopped.
 */
final class QueueConsumer
{
    private const float DEFAULT_MAX_WAIT = 0.05;
    private const float DEFAULT_RESYNC_INTERVAL = 0.1;
    private const float DEFAULT_SHUTDOWN_GRACE = 10.0;

    /** @var array<string, true> every job id this process has already admitted */
    private array $known = [];

    private bool $running = false;

    private bool $stopping = false;

    /**
     * @param array<string, true> $knownIds the job ids already admitted to
     *                                      $queue by restoreFromStorage();
     *                                      the loop re-syncs everything else
     */
    public function __construct(
        private JobDispatcher $dispatcher,
        private ComponentQueue $queue,
        private string $logPath,
        private Clock $clock = new SystemClock(),
        private float $maxWait = self::DEFAULT_MAX_WAIT,
        private float $resyncInterval = self::DEFAULT_RESYNC_INTERVAL,
        private float $shutdownGrace = self::DEFAULT_SHUTDOWN_GRACE,
        private ?WorkerRegistry $registry = null,
        array $knownIds = [],
    ) {
        $this->known = $knownIds;
    }

    public function run(): void
    {
        $this->running = true;

        $this->installSignalHandlers();

        try {
            $this->runWhile(static fn (): bool => true);
        } finally {
            $this->restoreSignalHandlers();
            $this->running = false;
        }
    }

    /**
     * Run the loop until $done stops returning true (or stop() is called) -
     * the long-running shape for a benchmark, which decides on its own when
     * the work is finished instead of waiting for a signal. The loop is the
     * same as run()'s: re-sync the journal, tick, repeat; start() forks the
     * workers (idempotent), and shutdown happens here on the way out.
     *
     * @param Closure(): bool $done returns true while the loop should keep going
     */
    public function runWhile(Closure $done): void
    {
        $this->running = true;

        $this->dispatcher->start();

        $nextResync = $this->clock->now() + $this->resyncInterval;

        try {
            while (!$this->stopping && $done()) {
                if ($this->clock->now() >= $nextResync) {
                    $this->resync();
                    $nextResync = $this->clock->now() + $this->resyncInterval;
                }

                $this->tick();
            }
        } finally {
            $this->running = false;
            $this->dispatcher->shutdown($this->shutdownGrace);
        }
    }

    /**
     * Asks the loop to stop. Idempotent, safe from a signal handler, and
     * does nothing itself - the shutdown runs in run(), on the loop's own
     * thread of control.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * One pass of the loop: the same shape as QueueRuntime::tick(), built
     * only on the dispatcher's public surface. Public because a benchmark
     * drives the loop itself (see runWhile()).
     */
    public function tick(): int
    {
        $dispatched = $this->dispatcher->dispatchPending();

        // Between the dispatch and the collect the workers are BUSY with a
        // known current job, so this is the only instant that captures every
        // dispatched job - see WorkerRegistry::capture().
        $this->registry?->capture();

        $wait = $this->waitTime();

        if ($this->dispatcher->hasWorkInFlight()) {
            $this->dispatcher->collect($wait);
        } else {
            $this->dispatcher->collect();
            $this->sleep($wait);
        }

        $this->dispatcher->requeueExpired();

        $this->registry?->settle();
        $this->registry?->maybeWrite();

        return $dispatched;
    }

    /**
     * Pull every journal row this process has not seen yet. Terminal rows
     * are remembered but not pushed - a job finished while we were away is
     * not someone's work anymore - while a non-terminal row, published by
     * another process after we started, goes into the queue and is
     * dispatched on a later tick. Delayed jobs keep their remaining wait.
     */
    private function resync(): void
    {
        foreach (new FileStorage($this->logPath)->load() as $id => $data) {
            if (isset($this->known[$id])) {
                continue;
            }

            $job = Job::fromArray($data);
            $this->known[$id] = true;

            if ($job->getState()->isTerminal()) {
                continue;
            }

            $delay = $job->getState() === JobState::DELAYED
                ? max(0, (int) ceil(($job->getAvailableAt() ?? $this->clock->now()) - $this->clock->now()))
                : 0;

            $this->queue->push($job, $delay);
        }
    }

    /**
     * How long this tick may wait: until the next deadline the consumer
     * owns, capped at $maxWait so signals stay responsive and a journal
     * resync is never far away.
     */
    private function waitTime(): float
    {
        $deadline = $this->dispatcher->nextDeadline();

        if ($deadline === null) {
            return $this->maxWait;
        }

        return max(0.0, min($this->maxWait, $deadline - $this->clock->now()));
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0.0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    private function installSignalHandlers(): void
    {
        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, $this->stop(...));
        }
    }

    private function restoreSignalHandlers(): void
    {
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
    }
}
