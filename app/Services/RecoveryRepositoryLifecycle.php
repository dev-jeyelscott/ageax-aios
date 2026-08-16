<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * AIOS-controlled Git lifecycle for the Workflow Recovery Engineer's own repository (AIOS's
 * application repository), distinct from CoderRepositoryGuard/ProjectGitState/TaskCommitter,
 * which govern the managed *project* repositories that Coder/Reviewer operate on. This service
 * never stashes, resets, or discards anything; a dirty repository simply fails preflight.
 */
class RecoveryRepositoryLifecycle
{
    /** @return array{clean: bool, head_sha: ?string, errors: array<int, string>} */
    public function preflight(string $path): array
    {
        $head = $this->run($path, ['git', 'rev-parse', 'HEAD']);
        $status = $this->run($path, ['git', 'status', '--porcelain']);

        if (! $head['successful'] || ! $status['successful']) {
            return ['clean' => false, 'head_sha' => null, 'errors' => ['Git could not inspect the recovery repository.']];
        }

        return ['clean' => trim($status['output']) === '', 'head_sha' => trim($head['output']), 'errors' => []];
    }

    /** @return array<int, string>|null */
    public function changedFilesFromBase(string $path, string $baseSha): ?array
    {
        $staged = $this->files($path, ['git', 'diff', '--cached', '--name-only', '--no-renames', '-z', $baseSha, '--']);
        $unstaged = $this->files($path, ['git', 'diff', '--name-only', '--no-renames', '-z', '--']);
        $untracked = $this->files($path, ['git', 'ls-files', '--others', '--exclude-standard', '-z', '--']);

        if ($staged === null || $unstaged === null || $untracked === null) {
            return null;
        }

        return $this->normalize([...$staged, ...$unstaged, ...$untracked]);
    }

    /**
     * @param  array<int, string>  $changedFiles
     * @return array{passed: bool, checks: array<string, bool>, evidence: array<string, mixed>}
     */
    public function validate(string $path, array $changedFiles): array
    {
        $diffCheck = $this->run($path, ['git', 'diff', '--check']);
        $secretScan = $this->run($path, ['rg', '--hidden', '--glob', '!.git/**', '--glob', '!node_modules/**', '--glob', '!vendor/**', '(AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|ghp_[A-Za-z0-9]{36})']);
        $forbidden = collect($changedFiles)->filter(fn (string $file): bool => $this->isForbiddenFile($file))->values();
        $commandChecks = $this->runConfiguredCommands($path);

        $checks = [
            'git_diff_check' => $diffCheck['successful'],
            'secret_scan' => $secretScan['exit_code'] === 1,
            'forbidden_file_check' => $forbidden->isEmpty(),
            'configured_validation_commands' => $commandChecks['passed'],
        ];

        return [
            'passed' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'evidence' => [
                'forbidden_files' => $forbidden->all(),
                'configured_validation_commands' => $commandChecks['evidence'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $changedFiles
     */
    public function commit(string $path, array $changedFiles, string $baseSha, string $message): ?string
    {
        $changedFiles = $this->normalize($changedFiles);
        if ($changedFiles === []) {
            return null;
        }

        $preflight = $this->preflight($path);
        // Confirm the repository head has not moved since the recovery engineer's base was
        // recorded and that only the expected files changed, mirroring TaskCommitter's guard
        // for managed project commits.
        if ($preflight['head_sha'] !== $baseSha) {
            return null;
        }

        $actualChanges = $this->changedFilesFromBase($path, $baseSha);
        if ($actualChanges === null || $actualChanges !== $changedFiles) {
            return null;
        }

        $add = $this->run($path, ['git', '--literal-pathspecs', 'add', '--', ...$changedFiles]);
        if (! $add['successful']) {
            return null;
        }

        $commit = $this->run($path, ['git', '--literal-pathspecs', 'commit', '--only', '-m', $message, '--', ...$changedFiles]);
        if (! $commit['successful']) {
            return null;
        }

        $head = $this->run($path, ['git', 'rev-parse', 'HEAD']);

        return $head['successful'] ? trim($head['output']) : null;
    }

    /** @return array{passed: bool, evidence: array<int, array{command: string, exit_code: ?int, passed: bool}>} */
    private function runConfiguredCommands(string $path): array
    {
        $evidence = [];
        foreach ($this->safeConfiguredCommands() as $command) {
            $result = Process::path($path)->timeout((int) config('aios.execution_timeout'))->run(preg_split('/\s+/', trim($command)) ?: []);
            $evidence[] = ['command' => $command, 'exit_code' => $result->exitCode(), 'passed' => $result->successful()];
            if ($result->failed()) {
                return ['passed' => false, 'evidence' => $evidence];
            }
        }

        return ['passed' => true, 'evidence' => $evidence];
    }

    /** @return array<int, string> */
    private function safeConfiguredCommands(): array
    {
        $allowedExecutables = ['php', 'composer', 'vendor/bin/pest', './vendor/bin/pest', 'vendor/bin/phpstan', './vendor/bin/phpstan', 'vendor/bin/pint', './vendor/bin/pint'];

        return collect((array) config('aios.recovery_validation_commands'))
            ->filter(fn (mixed $command): bool => is_string($command)
                && preg_match('~^[A-Za-z0-9_./:=+,@-]+(?: [A-Za-z0-9_./:=+,@-]+)*$~', $command) === 1
                && in_array((preg_split('/\s+/', trim($command)) ?: [''])[0], $allowedExecutables, true))
            ->values()
            ->all();
    }

    private function isForbiddenFile(string $file): bool
    {
        $filename = basename($file);

        return $filename === '.env'
            || (Str::startsWith($filename, '.env.') && $filename !== '.env.example')
            || preg_match('/^(?:id_rsa|.*\.(?:pem|key))$/', $filename) === 1;
    }

    /**
     * @param  array<int, string>  $command
     * @return array<int, string>|null
     */
    private function files(string $path, array $command): ?array
    {
        $result = $this->run($path, $command);
        if (! $result['successful']) {
            return null;
        }

        return $this->normalize(explode("\0", $result['output']));
    }

    /**
     * @param  array<int, string>  $command
     * @return array{successful: bool, output: string, exit_code: ?int}
     */
    private function run(string $path, array $command): array
    {
        try {
            $result = Process::path($path)->run($command);

            return ['successful' => $result->successful(), 'output' => $result->output(), 'exit_code' => $result->exitCode()];
        } catch (Throwable) {
            return ['successful' => false, 'output' => '', 'exit_code' => null];
        }
    }

    /**
     * @param  array<int, string>  $files
     * @return array<int, string>
     */
    private function normalize(array $files): array
    {
        $files = array_values(array_unique(array_filter($files, fn (string $file): bool => $file !== '')));
        sort($files, SORT_STRING);

        return $files;
    }
}
