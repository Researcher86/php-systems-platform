<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Workers;

use PhpSystemsPlatform\Memory\ProcStatusReader;
use PhpWorkerPool\Protocol\Request as WorkerRequest;
use RuntimeException;

/**
 * PLAN Step 16's measurement: run N pool workers, have each report its own
 * memory before and after it holds a real allocation, and turn that into
 * the comparison the plan asks for - shared initial memory vs memory after
 * mutation, and how the total scales with worker count.
 *
 * Split in two deliberately. `run()` needs a real pool - it fans `$workers`
 * memory.hold requests out over one ConcurrentTaskRunner, all in flight
 * before any is awaited, so N idle workers each pick up exactly one - and is
 * exercised by ServeIntegrationTest against the real thing. `aggregate()` is
 * the arithmetic alone, given the answers as plain arrays, which is what
 * WorkerMemoryBenchmarkTest can pin down with fixed numbers instead of a
 * live process.
 */
final readonly class WorkerMemoryBenchmark
{
    public function __construct(
        private ProcStatusReader $statusReader = new ProcStatusReader(),
    ) {
    }

    /**
     * @return array{workers: int, workers_observed: int, parent_rss: ?int, before_avg_rss: ?int, before_min_rss: ?int, before_max_rss: ?int, after_avg_rss: ?int, after_min_rss: ?int, after_max_rss: ?int, total_before_rss: ?int, total_after_rss: ?int}
     */
    public function run(ConcurrentTaskRunner $runner, int $workers, int $elements, int $masterPid): array
    {
        $requests = [];

        for ($i = 0; $i < $workers; $i++) {
            $requests[] = new WorkerRequest('memory.hold', ['elements' => $elements]);
        }

        try {
            $answers = $runner->run(...$requests);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('The memory pool could not answer for %d worker(s): %s', $workers, $e->getMessage()), 0, $e);
        }

        return $this->aggregate($workers, $this->safeRss($masterPid), $answers);
    }

    /**
     * @param list<array{pid: int, before: array<string, mixed>, after: array<string, mixed>}> $answers
     *
     * @return array{workers: int, workers_observed: int, parent_rss: ?int, before_avg_rss: ?int, before_min_rss: ?int, before_max_rss: ?int, after_avg_rss: ?int, after_min_rss: ?int, after_max_rss: ?int, total_before_rss: ?int, total_after_rss: ?int}
     */
    public function aggregate(int $workers, ?int $parentRss, array $answers): array
    {
        $before = array_map(static fn (array $answer): ?int => self::intOrNull($answer['before']['rss'] ?? null), $answers);
        $after = array_map(static fn (array $answer): ?int => self::intOrNull($answer['after']['rss'] ?? null), $answers);

        return [
            'workers' => $workers,
            'workers_observed' => count($answers),
            'parent_rss' => $parentRss,
            'before_avg_rss' => self::avg($before),
            'before_min_rss' => self::extreme($before, min(...)),
            'before_max_rss' => self::extreme($before, max(...)),
            'after_avg_rss' => self::avg($after),
            'after_min_rss' => self::extreme($after, min(...)),
            'after_max_rss' => self::extreme($after, max(...)),
            'total_before_rss' => self::total($parentRss, $before),
            'total_after_rss' => self::total($parentRss, $after),
        ];
    }

    private function safeRss(int $pid): ?int
    {
        try {
            $fields = $this->statusReader->read($pid);
        } catch (RuntimeException) {
            return null;
        }

        return self::intOrNull($fields['VmRSS'] ?? null);
    }

    /**
     * @param list<?int> $values
     */
    private static function avg(array $values): ?int
    {
        $present = array_values(array_filter($values, static fn (?int $v): bool => $v !== null));

        return $present === [] ? null : intdiv(array_sum($present), count($present));
    }

    /**
     * @param list<?int>              $values
     * @param \Closure(list<int>): int $reduce
     */
    private static function extreme(array $values, \Closure $reduce): ?int
    {
        $present = array_values(array_filter($values, static fn (?int $v): bool => $v !== null));

        // min()/max() take the array itself, not a spread - a spread of one
        // element calls min(int), which min() rejects outright.
        return $present === [] ? null : $reduce($present);
    }

    /**
     * The parent plus every worker's own RSS - null the moment any of them
     * is unmeasurable, because a partial sum would understate the total
     * without saying so.
     *
     * @param list<?int> $values
     */
    private static function total(?int $parentRss, array $values): ?int
    {
        if ($parentRss === null || in_array(null, $values, true)) {
            return null;
        }

        return $parentRss + array_sum($values);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
