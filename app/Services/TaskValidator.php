<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;

class TaskValidator
{
    public function __construct(private WorkspacePathResolver $paths) {}

    /**
     * @param  array<int, string>|null  $changedFiles
     * @return array{passed: bool, checks: array<string, bool>}
     */
    public function validate(Task $task, ?string $baseSha = null, ?array $changedFiles = null): array
    {
        $path = $this->paths->assertProjectPath($task->project->path);
        $diffCommand = $baseSha === null
            ? ['git', 'diff', '--check']
            : ['git', 'diff', '--check', $baseSha, '--'];
        $diff = Process::path($path)->run($diffCommand);
        $secrets = Process::path($path)->run(['rg', '--hidden', '--glob', '!.git/**', '--glob', '!node_modules/**', '(AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|ghp_[A-Za-z0-9]{36})']);
        $candidateFiles = $changedFiles === null ? $this->changedFiles($path) : collect($changedFiles);
        $forbiddenFiles = $candidateFiles->contains(fn (string $file): bool => $this->isForbiddenFile($file));
        $checks = [
            'git_diff_check' => $diff->successful(),
            'secret_scan' => $secrets->exitCode() === 1,
            'forbidden_file_check' => ! $forbiddenFiles,
            'task_verification' => $this->runVerificationCommands($task),
        ];

        return ['passed' => ! in_array(false, $checks, true), 'checks' => $checks];
    }

    private function isForbiddenFile(string $file): bool
    {
        $filename = basename($file);

        return $filename === '.env'
            || (Str::startsWith($filename, '.env.') && $filename !== '.env.example')
            || preg_match('/^(?:id_rsa|.*\.(?:pem|key))$/', $filename) === 1;
    }

    private function runVerificationCommands(Task $task): bool
    {
        $commands = $this->verificationCommands($task);
        if ($commands === null) {
            return false;
        }

        foreach ($commands as $command) {
            if (! $this->isSafeVerificationCommand($command)) {
                return false;
            }

            $result = Process::path($this->paths->assertProjectPath($task->project->path))
                ->timeout((int) config('aios.execution_timeout'))
                ->run(preg_split('/\s+/', trim($command)) ?: []);

            if ($result->failed()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string>|null */
    private function verificationCommands(Task $task): ?array
    {
        $rawCommands = $task->getRawOriginal('verification_commands');
        if ($rawCommands === null) {
            return [];
        }

        try {
            $commands = json_decode($rawCommands, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($commands) || collect($commands)->contains(fn (mixed $command): bool => ! is_string($command))) {
            return null;
        }

        return array_values($commands);
    }

    private function isSafeVerificationCommand(string $command): bool
    {
        if (preg_match('~^[A-Za-z0-9_./:=+,@-]+(?: [A-Za-z0-9_./:=+,@-]+)*$~', $command) !== 1) {
            return false;
        }

        $executable = preg_split('/\s+/', trim($command))[0] ?? '';

        return in_array($executable, ['php', 'composer', 'npm', 'pnpm', 'yarn', 'bun', 'npx', 'git', 'vendor/bin/pest', './vendor/bin/pest', 'vendor/bin/phpstan', './vendor/bin/phpstan', 'vendor/bin/pint', './vendor/bin/pint'], true);
    }

    /** @return Collection<int, non-falsy-string> */
    private function changedFiles(string $path): Collection
    {
        $status = Process::path($path)->run(['git', 'status', '--porcelain']);

        return collect(preg_split('/\R/', $status->output()) ?: [])
            ->filter(fn (string $line): bool => $line !== '')
            ->map(fn (string $line): string => trim(substr($line, 3)))
            ->filter()
            ->values();
    }
}
