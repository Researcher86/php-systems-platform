<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Support;

use Closure;
use RuntimeException;

/**
 * A child process this process started, plus the small toolkit the commands
 * that own a whole platform (demo, load test, failure experiments) share:
 * port and condition polling, stale database cleanup, temp-tree handling.
 *
 *   start()  proc_open with stdout and stderr appended to named log files, so
 *            a child's output survives a failure that killed its parent
 *   stop()   SIGTERM, wait, then the exit code - a child that had to be
 *            SIGKILLed is reported non-zero, never as a polite stop, because
 *            the graceful-shutdown checks built on this rely on the difference
 *
 * There is no daemon mode and no pid file: a child belongs to the process
 * that started it, and the handle returned here is what proves it.
 */
final class OwnedProcess
{
    public const float START_DEADLINE_SECONDS = 25.0;

    public const float STOP_DEADLINE_SECONDS = 20.0;

    private const float POLL_INTERVAL_SECONDS = 0.05;

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
     * A non-empty $env is the child's whole environment, not a patch on this
     * process's one, so a child's configuration never depends on the shell
     * that started the command. An empty $env inherits this process's
     * environment, putenv() changes included.
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

        // Watching the child too means one that dies on start (a config
        // error, a port already taken) fails at once instead of after the
        // whole deadline - and is never mistaken for ready because someone
        // else answers on its port.
        $ready = self::waitForQuietly(
            static fn (): bool => !$child->isRunning() || self::portAnswers($host, $port, self::PORT_PROBE_CONNECT_SECONDS),
            $deadlineSeconds,
        );

        if ($ready && $child->isRunning()) {
            return $child;
        }

        $exitCode = $child->stop();
        $problem = $ready
            ? sprintf('exited with code %d before answering on tcp://%s:%d', $exitCode, $host, $port)
            : sprintf('did not answer on tcp://%s:%d within %.0fs', $host, $port, $deadlineSeconds);

        throw new RuntimeException(sprintf('The %s process %s. Output: %s', $name, $problem, $child->output()));
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
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
            // Ignored SIGTERM: make it gone, and report it as non-zero below.
            proc_terminate($this->process, 9);
        }

        // proc_close() returns the exit code proc_get_status() already
        // observed (PHP >= 8.3), so the wait above does not lose it.
        $code = proc_close($this->process);
        $this->process = null;

        return $exited ? $code : ($code === 0 ? 1 : $code);
    }

    /** Everything the child wrote, both streams: the only explanation a failed run has. */
    public function output(): string
    {
        return trim((string) @file_get_contents($this->stdoutPath) . "\n" . (string) @file_get_contents($this->stderrPath));
    }

    /**
     * Whether a TCP connect succeeds within $timeoutSeconds. It keeps retrying
     * until then, so the full timeout is spent whenever nothing listens: keep
     * it short for "is something already running" checks.
     */
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

            usleep((int) (self::POLL_INTERVAL_SECONDS * 1_000_000));
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
     * Poll $probe until it holds or the deadline passes; one last probe after
     * the deadline so a condition met during the final sleep still counts.
     *
     * @param Closure(): bool $probe
     */
    public static function waitForQuietly(
        Closure $probe,
        float $deadlineSeconds,
        float $intervalSeconds = self::POLL_INTERVAL_SECONDS,
    ): bool {
        $deadline = microtime(true) + $deadlineSeconds;

        while (microtime(true) < $deadline) {
            if ($probe()) {
                return true;
            }

            usleep((int) ($intervalSeconds * 1_000_000));
        }

        return $probe();
    }

    /**
     * A serve that was killed can leave the daemonized database server it
     * started behind. Its pid file is the only unambiguous owner, so it is
     * read (and the server stopped) before a run wipes the data directory
     * for a fresh platform. A port that answers without a pid file belongs
     * to someone else and is left alone.
     */
    public static function stopStaleDatabaseServer(
        string $host,
        int $port,
        string $dataDir,
        float $deadlineSeconds = self::STOP_DEADLINE_SECONDS,
    ): void {
        if (!self::portAnswers($host, $port, 0.5)) {
            return;
        }

        $pidFile = $dataDir . '/minidb.pid';
        $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;

        if ($pid > 0) {
            posix_kill($pid, SIGTERM);
            self::waitFor(
                static fn (): bool => !posix_kill($pid, 0),
                $deadlineSeconds,
                'the stale database server did not stop in time.',
            );
        }
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
