<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Support;

use Closure;
use RuntimeException;

/**
 * A child process this process started, and the two questions every caller
 * has about it: is it still running, and did it stop when it was asked to.
 *
 * PLAN Step 29's load run and Step 30's failure experiments both need to own
 * a platform for the length of a run - a serve, a worker pool, a cache server
 * - and both need to be able to say afterwards that what they started is gone.
 * That is the same lifecycle written once here rather than once per command:
 *
 *   start()  proc_open with stdout and stderr appended to named log files, so
 *            a child's own output survives a failure that killed its parent
 *   stop()   SIGTERM, then wait for the exit, then the exit code - a process
 *            that had to be killed is reported as such rather than as one that
 *            stopped politely, because that difference is the whole claim
 *            Step 28's graceful-shutdown scenario and Step 30's crash
 *            experiment exist to make
 *
 * There is no daemon mode and no pid file anywhere in this project: a child
 * belongs to the process that started it, and the handle returned here is
 * what proves it.
 */
final class OwnedProcess
{
    public const float START_DEADLINE_SECONDS = 25.0;

    public const float STOP_DEADLINE_SECONDS = 20.0;

    private const float PORT_PROBE_INTERVAL_SECONDS = 0.05;

    private const float PORT_PROBE_CONNECT_SECONDS = 0.2;

    /** @var resource|null */
    private $process = null;

    private int $pid = 0;

    private function __construct(
        private readonly string $stdoutPath,
        private readonly string $stderrPath,
    ) {
    }

    /**
     * Start a child and hand back the handle that owns it.
     *
     * The environment is the child's whole environment when $env is given, not
     * a patch, because a child that inherited a developer's shell and then
     * picked up two overrides is a platform whose configuration depends on
     * which terminal started it. Pass the full set you mean.
     *
     * @param list<string>         $argv   the child's command, argv-style
     * @param array<string, string> $env
     */
    public static function start(array $argv, string $name, string $logDir, array $env = []): self
    {
        self::mkdir($logDir);

        $child = new self($logDir . '/' . $name . '.out', $logDir . '/' . $name . '.err');
        $process = proc_open(
            $argv,
            [
                1 => ['file', $child->stdoutPath, 'a'],
                2 => ['file', $child->stderrPath, 'a'],
            ],
            $pipes,
            null,
            $env === [] ? null : $env,
        );

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Could not start the %s process.', $name));
        }

        $child->process = $process;
        $child->pid = (int) proc_get_status($process)['pid'];

        return $child;
    }

    /**
     * Start a child and wait for it to answer on a TCP port, which is the
     * only readiness signal that means "serving" rather than "launched".
     *
     * @param list<string>         $argv
     * @param array<string, string> $env
     */
    public static function startAndWaitForPort(
        array $argv,
        string $name,
        string $logDir,
        string $host,
        int $port,
        array $env = [],
        float $deadlineSeconds = self::START_DEADLINE_SECONDS,
    ): self {
        $child = self::start($argv, $name, $logDir, $env);

        if (!self::portAnswers($host, $port, $deadlineSeconds)) {
            $child->stop();

            throw new RuntimeException(sprintf(
                'The %s process did not answer on tcp://%s:%d within %.0fs. Output: %s',
                $name,
                $host,
                $port,
                $deadlineSeconds,
                $child->output(),
            ));
        }

        return $child;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        return is_resource($this->process) && (bool) proc_get_status($this->process)['running'];
    }

    /**
     * Ask the child to stop, the way an operator would, and wait for it.
     *
     * @return int the exit code, or 0 when there was no child left to stop
     */
    public function stop(float $deadlineSeconds = self::STOP_DEADLINE_SECONDS): int
    {
        if (!is_resource($this->process)) {
            return 0;
        }

        proc_terminate($this->process);

        $exited = self::waitForQuietly(
            fn (): bool => !proc_get_status($this->process)['running'],
            $deadlineSeconds,
        );

        if (!$exited) {
            // A child that ignored SIGTERM is not a child that stopped, and
            // waiting for it to change its mind would block the run it has
            // already decided to slow down. Make it gone, then say so with a
            // non-zero code instead of reporting a polite shutdown that did
            // not happen.
            proc_terminate($this->process, 9);
        }

        $code = proc_close($this->process);
        $this->process = null;

        return $exited ? $code : ($code === 0 ? 1 : $code);
    }

    /**
     * Stop the child the hard way, for a process that has to be gone before
     * the next phase of a run can start on the same port.
     */
    public function kill(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }
    }

    /** Everything the child wrote, both streams: the only explanation a failed run has. */
    public function output(): string
    {
        return trim((string) @file_get_contents($this->stdoutPath) . "\n" . (string) @file_get_contents($this->stderrPath));
    }

    public static function portAnswers(string $host, int $port, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', $host, $port),
                $code,
                $message,
                self::PORT_PROBE_CONNECT_SECONDS,
            );

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            usleep((int) (self::PORT_PROBE_INTERVAL_SECONDS * 1_000_000));
        }

        return false;
    }

    /**
     * @param Closure(): bool $probe
     *
     * @throws RuntimeException on timeout, naming what was being waited for
     */
    public static function waitFor(Closure $probe, float $deadlineSeconds, string $message): void
    {
        if (!self::waitForQuietly($probe, $deadlineSeconds)) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param Closure(): bool $probe
     */
    public static function waitForQuietly(Closure $probe, float $deadlineSeconds): bool
    {
        $deadline = microtime(true) + $deadlineSeconds;

        while (microtime(true) < $deadline) {
            if ($probe()) {
                return true;
            }

            usleep((int) (self::PORT_PROBE_INTERVAL_SECONDS * 1_000_000));
        }

        return $probe();
    }

    public static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create "%s".', $dir));
        }
    }

    /** Delete a directory tree, quietly: a run's cleanup is not a place to throw. */
    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
