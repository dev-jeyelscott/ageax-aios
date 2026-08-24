<?php

namespace App\Services;

use App\Models\Task;
use RuntimeException;

class ManagedValidationProcessCleanup
{
    public function __construct(private WorkspacePathResolver $paths) {}

    /**
     * Stop only validation processes previously recorded by AIOS for this Task.
     *
     * @return list<int>
     */
    public function terminateStaleProcesses(Task $task): array
    {
        $projectPath = $this->paths->assertProjectPath($task->project->path);
        $terminated = [];

        foreach ($this->recordedProcesses($task) as $process) {
            if (! $this->matchesRecordedProcess($process['pid'], $process['command'], $projectPath)) {
                continue;
            }

            $processIds = $this->processTree($process['pid']);
            $this->signal($processIds, SIGTERM);

            if ($this->waitForExit($processIds, 10)) {
                $terminated = [...$terminated, ...$processIds];

                continue;
            }

            $this->signal($processIds, SIGKILL);

            if (! $this->waitForExit($processIds, 10)) {
                throw new RuntimeException('A stale AIOS validation process could not be stopped.');
            }

            $terminated = [...$terminated, ...$processIds];
        }

        return array_values(array_unique($terminated));
    }

    /** @return list<array{pid: int, command: list<string>}> */
    private function recordedProcesses(Task $task): array
    {
        /** @var list<array{pid: int, command: list<string>}> $recorded */
        $recorded = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($task->attempts()->get(['id', 'task_id', 'validation_results']) as $attempt) {
            $attemptData = $attempt->toArray();
            $results = $attemptData['validation_results'] ?? null;

            if (! is_array($results)) {
                continue;
            }

            $processes = $results['managed_processes'] ?? null;

            if (! is_array($processes)) {
                continue;
            }

            foreach ($processes as $process) {
                if (! is_array($process)) {
                    continue;
                }

                $pid = $process['pid'] ?? null;
                $command = $process['command'] ?? null;

                if (! is_int($pid) || $pid <= 0 || ! is_array($command) || ! array_is_list($command)) {
                    continue;
                }

                $normalizedCommand = [];
                $validCommand = true;

                foreach ($command as $argument) {
                    if (! is_string($argument) || $argument === '') {
                        $validCommand = false;

                        break;
                    }

                    $normalizedCommand[] = $argument;
                }

                if (! $validCommand) {
                    continue;
                }

                $fingerprint = $pid."\0".implode("\0", $normalizedCommand);

                if (isset($seen[$fingerprint])) {
                    continue;
                }

                $seen[$fingerprint] = true;
                $recorded[] = [
                    'pid' => $pid,
                    'command' => $normalizedCommand,
                ];
            }
        }

        return $recorded;
    }

    /** @param list<string> $command */
    private function matchesRecordedProcess(int $pid, array $command, string $projectPath): bool
    {
        $cwd = realpath("/proc/{$pid}/cwd");
        $commandLine = @file_get_contents("/proc/{$pid}/cmdline");

        if ($cwd === false || $commandLine === false || realpath($projectPath) !== $cwd) {
            return false;
        }

        return array_values(array_filter(explode("\0", $commandLine), fn (string $argument): bool => $argument !== '')) === $command;
    }

    /** @return list<int> */
    private function processTree(int $pid): array
    {
        $processIds = [$pid];

        foreach (glob("/proc/{$pid}/task/*/children") ?: [] as $childrenPath) {
            $children = trim((string) @file_get_contents($childrenPath));

            foreach (preg_split('/\s+/', $children, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $child) {
                if (ctype_digit($child) && (int) $child > 0) {
                    $processIds = [...$processIds, ...$this->processTree((int) $child)];
                }
            }
        }

        return array_values(array_unique($processIds));
    }

    /** @param list<int> $processIds */
    private function signal(array $processIds, int $signal): void
    {
        foreach (array_reverse($processIds) as $pid) {
            if ($this->isRunning($pid)) {
                posix_kill($pid, $signal);
            }
        }
    }

    /** @param list<int> $processIds */
    private function waitForExit(array $processIds, int $attempts): bool
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if (! collect($processIds)->contains(fn (int $pid): bool => $this->isRunning($pid))) {
                return true;
            }

            usleep(100_000);
        }

        return ! collect($processIds)->contains(fn (int $pid): bool => $this->isRunning($pid));
    }

    private function isRunning(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");

        return is_string($stat) && preg_match('/\) ([^ ])/', $stat, $matches) === 1 && $matches[1] !== 'Z';
    }
}
