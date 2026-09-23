<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Cli;

/**
 * The whole CLI surface of the platform, in one place.
 *
 * Deliberately small - no framework, no DI, no command classes for their own
 * sake. Each command is an entry in the table below; the dispatch match in
 * run() grows a real arm as the corresponding platform feature lands. See
 * docs/architecture.md for where each command sits in the process model.
 */
final class PlatformCli
{
    /**
     * @var array<string, string>
     */
    private const COMMANDS = [
        'serve' => 'Start the HTTP server and application.',
        'worker' => 'Start the worker pool and queue consumer.',
        'queue:publish' => 'Publish sample jobs into the queue.',
        'queue:consume' => 'Run the queue consumer (pairs with worker pool).',
        'queue:status' => 'Show queue depth and job counters.',
        'status' => 'Show the state of every platform component.',
        'demo' => 'Run the complete end-to-end platform story.',
        'benchmark' => 'Run the platform benchmark suite.',
        'memory:demo' => 'Demonstrate fork() and copy-on-write memory behavior.',
        'workers:memory' => 'Measure worker process memory (1, 2, 4, 8 workers).',
        'failure:demo' => 'Reproduce the failure scenarios end to end.',
    ];

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        if ($command === 'help' || $command === '--help' || $command === '-h') {
            return $this->printHelp();
        }

        if (!isset(self::COMMANDS[$command])) {
            fwrite(STDERR, sprintf("Unknown command: %s\n", $command));
            fwrite(STDERR, "Run 'php bin/platform help' for the command list.\n");

            return 1;
        }

        return $this->dispatch($command);
    }

    private function printHelp(): int
    {
        $width = max(array_map('strlen', array_keys(self::COMMANDS)));

        fwrite(STDOUT, "PHP Systems Platform\n\n");
        fwrite(STDOUT, "Commands\n\n");

        foreach (self::COMMANDS as $name => $description) {
            fwrite(STDOUT, sprintf("  %-{$width}s  %s\n", $name, $description));
        }

        fwrite(STDOUT, "\nRun a command with\n");
        fwrite(STDOUT, "  php bin/platform <command>\n");

        return 0;
    }

    private function dispatch(string $command): int
    {
        return match ($command) {
            // Real handlers land with their implementation phase.
            default => $this->notImplemented($command),
        };
    }

    private function notImplemented(string $command): int
    {
        fwrite(STDOUT, sprintf("%s: not implemented yet - coming with the next implementation phase\n", $command));

        return 0;
    }
}
