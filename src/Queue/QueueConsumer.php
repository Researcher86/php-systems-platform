<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use Closure;
use PhpJobQueue\Dispatcher\JobDispatcher;
use PhpJobQueue\Job\Job;
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
 * journal is the only shared state. So the consumer cannot restore once and
 * run: it keeps QueueRuntime's tick shape (dispatch pending, apply every
 * answer, requeue expired) but also tails the journal on a short interval
 * and admits any job another process published since.
 *
 * The shutdown contract is QueueRuntime's too: SIGTERM and SIGINT only set
 * a flag, the loop notices it on its next pass, and workers get the
 * configured grace period on the loop's own thread. One-shot: a consumer
 * that has stopped stays stopped.
 */
final class QueueConsumer
{
    private const array SIGNALS = [SIGTERM, SIGINT];

    /**
     * Ids of the non-terminal jobs this process has admitted. The journal
     * tail also returns this consumer's own writes about them (PROCESSING,
     * a retry's READY/DELAYED), and re-admitting those would deliver the
     * same job twice; an id is dropped once its row turns terminal, so the
     * set stays bounded by the live jobs rather than the whole history.
     *
     * @var array<string, true>
     */
    private array $known;

    private readonly JournalTail $tail;

    private bool $stopping = false;

    /**
     * @param array<string, true> $knownIds the job ids already admitted to
     *                                      $queue by restoreFromStorage()
     */
    public function __construct(
        private readonly JobDispatcher $dispatcher,
        private readonly ComponentQueue $queue,
        string $logPath,
        private readonly Clock $clock = new SystemClock(),
        private readonly float $maxWait = 0.05,
        private readonly float $resyncInterval = 0.1,
        private readonly float $shutdownGrace = 10.0,
        private readonly ?WorkerRegistry $registry = null,
        array $knownIds = [],
    ) {
        $this->known = $knownIds;
        $this->tail = new JournalTail($logPath);
    }

    public function run(): void
    {
        pcntl_async_signals(true);
        $previous = [];

        foreach (self::SIGNALS as $signal) {
            $previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, $this->stop(...));
        }

        try {
            $this->runWhile(static fn (): bool => true);
        } finally {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
        }
    }

    /**
     * Run the loop while $keepRunning returns true and stop() has not been
     * called - the shape for a benchmark, which decides on its own when the
     * work is finished. The workers are shut down on the way out, even when
     * starting them failed halfway.
     *
     * @param Closure(): bool $keepRunning
     */
    public function runWhile(Closure $keepRunning): void
    {
        try {
            $this->dispatcher->start();
            $nextResync = $this->clock->now() + $this->resyncInterval;

            while (!$this->stopping && $keepRunning()) {
                if ($this->clock->now() >= $nextResync) {
                    $this->resync();
                    $nextResync = $this->clock->now() + $this->resyncInterval;
                }

                $this->tick();
            }
        } finally {
            $this->dispatcher->shutdown($this->shutdownGrace);
        }
    }

    /**
     * Asks the loop to stop. Idempotent and safe from a signal handler: the
     * shutdown itself runs in runWhile(), on the loop's own thread.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * One pass: QueueRuntime::tick(), built on the dispatcher's public surface.
     */
    private function tick(): void
    {
        $this->dispatcher->dispatchPending();

        // Between the dispatch and the collect every dispatched job is still
        // held by a BUSY worker, so this is the one instant that sees them
        // all - see WorkerRegistry::capture().
        $this->registry?->capture();

        $wait = $this->waitTime();

        if ($this->dispatcher->hasWorkInFlight()) {
            $this->dispatcher->collect($wait);
        } else {
            $this->dispatcher->collect();

            if ($wait > 0.0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        $this->dispatcher->requeueExpired();

        $this->registry?->settle();
        $this->registry?->maybeWrite();
    }

    /**
     * Admit every job another process published since the last pass. A job
     * first seen already terminal finished while we were away and is not
     * work; a DELAYED one keeps its own availableAt (the queue ignores the
     * push delay for anything but a CREATED job).
     */
    private function resync(): void
    {
        foreach ($this->tail->read() as $id => $data) {
            $job = Job::fromArray($data);

            if ($job->getState()->isTerminal()) {
                unset($this->known[$id]);

                continue;
            }

            if (isset($this->known[$id])) {
                continue;
            }

            $this->known[$id] = true;
            $this->queue->push($job);
        }
    }

    /**
     * Until the next deadline the dispatcher owns, capped at $maxWait so
     * signals stay responsive and a resync is never far away.
     */
    private function waitTime(): float
    {
        $deadline = $this->dispatcher->nextDeadline();

        if ($deadline === null) {
            return $this->maxWait;
        }

        return max(0.0, min($this->maxWait, $deadline - $this->clock->now()));
    }
}
