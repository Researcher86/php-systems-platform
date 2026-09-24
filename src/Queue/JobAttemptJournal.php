<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Queue;

use RuntimeException;

/**
 * The job metadata PLAN Step 19 asks for that the queue component simply
 * does not keep: `PhpJobQueue\Job\Job::toArray()` has no started_at,
 * completed_at or last_error - only id, type, payload, state, attempts,
 * maxAttempts, createdAt and availableAt. Attempts is a count; this is the
 * history behind it, one row per execution.
 *
 * An append-only log, the same shape as the queue's own journal and for the
 * same reason: JobExecutor runs inside a forked worker process, a different
 * one on every attempt, so there is no in-memory object for a "last error"
 * to live on between them - only a durable file every attempt, from
 * whichever process ran it, can append to.
 */
final readonly class JobAttemptJournal
{
    public function __construct(
        private string $logPath,
    ) {
    }

    public function record(string $jobId, int $attempt, float $startedAt, float $completedAt, ?string $error): void
    {
        $line = json_encode([
            'job_id' => $jobId,
            'attempt' => $attempt,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'last_error' => $error,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (@file_put_contents($this->logPath, $line . "\n", FILE_APPEND) === false) {
            throw new RuntimeException(sprintf('Could not write to "%s".', $this->logPath));
        }
    }

    /**
     * Every attempt ever recorded for one job, in the order they ran.
     *
     * @return list<array{job_id: string, attempt: int, started_at: float, completed_at: float, last_error: ?string}>
     */
    public function forJob(string $jobId): array
    {
        return array_values(array_filter(
            $this->rows(),
            static fn (array $row): bool => $row['job_id'] === $jobId,
        ));
    }

    /**
     * Every attempt for every job, in the order they were recorded. A
     * malformed final line - a write torn by a crash - is dropped rather
     * than thrown on, the same tolerance the queue's own journal has for
     * the same reason.
     *
     * @return list<array{job_id: string, attempt: int, started_at: float, completed_at: float, last_error: ?string}>
     */
    public function rows(): array
    {
        if (!file_exists($this->logPath)) {
            return [];
        }

        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException(sprintf('Could not read "%s".', $this->logPath));
        }

        $rows = [];
        $lastIndex = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded) || !isset($decoded['job_id'], $decoded['attempt'], $decoded['started_at'], $decoded['completed_at'])) {
                if ($index === $lastIndex) {
                    break;
                }

                throw new RuntimeException(sprintf('Malformed line %d in "%s".', $index + 1, $this->logPath));
            }

            $rows[] = [
                'job_id' => (string) $decoded['job_id'],
                'attempt' => (int) $decoded['attempt'],
                'started_at' => (float) $decoded['started_at'],
                'completed_at' => (float) $decoded['completed_at'],
                'last_error' => isset($decoded['last_error']) ? (string) $decoded['last_error'] : null,
            ];
        }

        return $rows;
    }
}
