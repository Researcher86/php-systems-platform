<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Support;

use Closure;
use Throwable;

/**
 * The resources one command started, in the order it has to give them back.
 *
 * A long-running command here starts up to four things that outlive the PHP
 * call that started them: a daemonized database server (a pid file), a cache
 * server and a worker-pool Master (both direct children), and the client
 * connections to them. Getting them all back is the part that is easy to get
 * wrong by writing it out at every exit: a `return 1` in a constructor's
 * catch block that forgets one line leaks a process that outlives the command
 * and is then *adopted* by the next run, so it never gets cleaned up at all.
 * That is exactly what happened to the worker pool on two of serve()'s five
 * error paths.
 *
 * So ownership is recorded once, here, instead of being re-asserted at every
 * exit:
 *
 *   push()  a step is pushed only once this process really holds the resource
 *           it releases, so "do I own it" is answered by whether there is a
 *           step at all - not by a boolean that a later edit can forget to
 *           consult
 *   run()   every step, newest first, on every path out of the command. One
 *           step that throws does not skip the rest: a cleanup that gives up
 *           halfway is the failure mode this class exists to remove
 *
 * Newest first is the natural order - a client connection is released before
 * the server it talks to, a pool before the cache its workers read through -
 * and it is the order the steps were pushed in, so the call site reads top to
 * bottom in the order things are acquired.
 */
final class ShutdownStack
{
    /** @var list<array{name: string, step: Closure(): void}> */
    private array $steps = [];

    private bool $ran = false;

    public function __construct(
        private readonly string $command = 'command',
    ) {
    }

    /**
     * Register one release step. A name is not decoration: it is what a
     * failing cleanup reports, so "the cache server did not stop" is a line
     * an operator can act on instead of a silent finally that swallowed it.
     */
    public function push(Closure $step, string $name = ''): void
    {
        if ($this->ran) {
            throw new \LogicException('Cannot push a shutdown step after the stack has run.');
        }

        $this->steps[] = ['name' => $name, 'step' => $step];
    }

    /**
     * Release everything, newest first. Idempotent: a second call is a no-op,
     * so a command that reaches the end of its body and then unwinds through
     * an outer finally does not stop the same server twice.
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
                fwrite(STDERR, sprintf(
                    "[%s] could not release the %s: %s\n",
                    $this->command,
                    $entry['name'] === '' ? 'resource' : $entry['name'],
                    $e->getMessage(),
                ));
            }
        }
    }

    public function ran(): bool
    {
        return $this->ran;
    }
}
