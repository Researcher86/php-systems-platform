<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Support;

use Closure;
use Throwable;

/**
 * The resources one command started, released in reverse order on every
 * path out of it.
 *
 * A command can start a daemonized database server, a cache server, a
 * worker-pool Master and clients to them. Releasing them by hand at each exit
 * is easy to get wrong, and a leaked server is then adopted by the next run
 * and never cleaned up. So ownership is recorded once:
 *
 *   push()  only once this process really holds the resource, so "do I own
 *           it" is simply whether a step exists
 *   run()   every step, newest first (a client before its server, a pool
 *           before the cache its workers use); a step that throws is
 *           reported and does not skip the rest
 */
final class ShutdownStack
{
    /** @var list<array{name: string, step: Closure(): void}> */
    private array $steps = [];

    private bool $ran = false;

    /** @param resource $errorOutput where a failing step is reported */
    public function __construct(
        private readonly string $command = 'command',
        private readonly mixed $errorOutput = STDERR,
    ) {
    }

    /** Register one release step; $name is what a failing step reports. */
    public function push(Closure $step, string $name = ''): void
    {
        if ($this->ran) {
            throw new \LogicException('Cannot push a shutdown step after the stack has run.');
        }

        $this->steps[] = ['name' => $name, 'step' => $step];
    }

    /**
     * Release everything, newest first. Idempotent, so an explicit call
     * followed by an outer finally does not stop the same server twice.
     */
    public function run(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;
        $steps = $this->steps;
        $this->steps = [];

        foreach (array_reverse($steps) as $entry) {
            try {
                ($entry['step'])();
            } catch (Throwable $e) {
                fwrite($this->errorOutput, sprintf(
                    "[%s] could not release the %s: %s\n",
                    $this->command,
                    $entry['name'] === '' ? 'resource' : $entry['name'],
                    $e->getMessage(),
                ));
            }
        }
    }
}
