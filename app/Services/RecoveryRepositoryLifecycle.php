<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * AIOS-controlled Git lifecycle for the Workflow Recovery Engineer's own repository (AIOS's
 * application repository), distinct from CoderRepositoryGuard/ProjectGitState/TaskCommitter,
 * which govern the managed project repositories that Coder/Reviewer operate on. This service
 * never stashes, resets, or discards anything; a dirty repository simply fails preflight.
 */
class RecoveryRepositoryLifecycle
{
    /**
     * Inspect the recovery repository without changing its Git state.
     *
     * @return array{clean: bool, head_sha: ?string, errors: list<string>}
     */
    public function preflight(string $path): array
    {
        $head = $this->run($path, ['git', 'rev-parse', 'HEAD']);
        $status = $this->run($path, ['git', 'status', '--porcelain']);

        if (! $head['successful'] || ! $status['successful']) {
            return [
                'clean' => false,
                'head_sha' => null,
                'errors' => [
                    'Git could not inspect the recovery repository.',
                ],
            ];
        }

        return [
            'clean' => trim($status['output']) === '',
            'head_sha' => trim($head['output']),
            'errors' => [],
        ];
    }

    /**
     * Independently derive the exact repository-relative change set from the recorded base.
     *
     * @return list<string>|null
     */
    public function changedFilesFromBase(string $path, string $baseSha): ?array
    {
        $staged = $this->files(
            $path,
            [
                'git',
                'diff',
                '--cached',
                '--name-only',
                '--no-renames',
                '-z',
                $baseSha,
                '--',
            ],
        );

        $unstaged = $this->files(
            $path,
            [
                'git',
                'diff',
                '--name-only',
                '--no-renames',
                '-z',
                '--',
            ],
        );

        $untracked = $this->files(
            $path,
            [
                'git',
                'ls-files',
                '--others',
                '--exclude-standard',
                '-z',
                '--',
            ],
        );

        if ($staged === null || $unstaged === null || $untracked === null) {
            return null;
        }

        return $this->normalize([
            ...$staged,
            ...$unstaged,
            ...$untracked,
        ]);
    }

    /**
     * Independently validate the exact Recovery Engineer proposal.
     *
     * @param  array<int, string>  $changedFiles
     * @return array{
     *     passed: bool,
     *     checks: array<string, bool>,
     *     evidence: array<string, mixed>
     * }
     */
    public function validate(string $path, array $changedFiles): array
    {
        $trackedDiffCheck = $this->run(
            $path,
            [
                'git',
                'diff',
                '--check',
            ],
        );

        $untrackedDiffCheck = $this->validateUntrackedWhitespace($path);

        $secretScan = $this->run(
            $path,
            [
                'rg',
                '--hidden',
                '--glob',
                '!.git/**',
                '--glob',
                '!node_modules/**',
                '--glob',
                '!vendor/**',
                '(AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|ghp_[A-Za-z0-9]{36})',
                '.',
            ],
        );

        $forbidden = collect($changedFiles)
            ->filter(
                fn (string $file): bool => $this->isForbiddenFile($file),
            )
            ->values();

        $commandChecks = $this->runConfiguredCommands($path);

        $checks = [
            'git_diff_check' => $trackedDiffCheck['successful']
                && $untrackedDiffCheck['passed'],
            'secret_scan' => $secretScan['exit_code'] === 1,
            'forbidden_file_check' => $forbidden->isEmpty(),
            'configured_validation_commands' => $commandChecks['passed'],
        ];

        return [
            'passed' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'evidence' => [
                'git_diff_check' => [
                    'tracked_exit_code' => $trackedDiffCheck['exit_code'],
                    'untracked_files' => $untrackedDiffCheck['evidence'],
                ],
                'secret_scan' => [
                    'exit_code' => $secretScan['exit_code'],
                ],
                'forbidden_files' => $forbidden->all(),
                'configured_validation_commands' => $commandChecks['evidence'],
            ],
        ];
    }

    /**
     * Stage and commit only the exact AIOS-validated file set.
     *
     * @param  array<int, string>  $changedFiles
     */
    public function commit(
        string $path,
        array $changedFiles,
        string $baseSha,
        string $message,
    ): ?string {
        $changedFiles = $this->normalize($changedFiles);

        if ($changedFiles === []) {
            return null;
        }

        $preflight = $this->preflight($path);

        if ($preflight['head_sha'] !== $baseSha) {
            return null;
        }

        $actualChanges = $this->changedFilesFromBase($path, $baseSha);

        if (
            $actualChanges === null
            || $actualChanges !== $changedFiles
        ) {
            return null;
        }

        $add = $this->run(
            $path,
            [
                'git',
                '--literal-pathspecs',
                'add',
                '--',
                ...$changedFiles,
            ],
        );

        if (! $add['successful']) {
            return null;
        }

        $commit = $this->run(
            $path,
            [
                'git',
                '--literal-pathspecs',
                'commit',
                '--only',
                '-m',
                $message,
                '--',
                ...$changedFiles,
            ],
        );

        if (! $commit['successful']) {
            return null;
        }

        $head = $this->run(
            $path,
            [
                'git',
                'rev-parse',
                'HEAD',
            ],
        );

        return $head['successful']
            ? trim($head['output'])
            : null;
    }

    /**
     * Validate whitespace in untracked files because git diff --check does not inspect them.
     *
     * @return array{
     *     passed: bool,
     *     evidence: list<array{
     *         path: string,
     *         exit_code: ?int,
     *         passed: bool
     *     }>
     * }
     */
    private function validateUntrackedWhitespace(string $path): array
    {
        $untracked = $this->files(
            $path,
            [
                'git',
                'ls-files',
                '--others',
                '--exclude-standard',
                '-z',
                '--',
            ],
        );

        if ($untracked === null) {
            return [
                'passed' => false,
                'evidence' => [
                    [
                        'path' => '(untracked inspection)',
                        'exit_code' => null,
                        'passed' => false,
                    ],
                ],
            ];
        }

        $evidence = [];

        foreach ($untracked as $file) {
            $result = $this->run(
                $path,
                [
                    'git',
                    'diff',
                    '--no-index',
                    '--check',
                    '--',
                    '/dev/null',
                    $file,
                ],
            );

            /*
             * git diff --no-index returns 1 for a normal clean difference and
             * a larger failure code when --check finds whitespace errors.
             */
            $passed = in_array(
                $result['exit_code'],
                [0, 1],
                true,
            );

            $evidence[] = [
                'path' => $file,
                'exit_code' => $result['exit_code'],
                'passed' => $passed,
            ];

            if (! $passed) {
                return [
                    'passed' => false,
                    'evidence' => $evidence,
                ];
            }
        }

        return [
            'passed' => true,
            'evidence' => $evidence,
        ];
    }

    /**
     * Execute approved deterministic validation commands inside the selected repository.
     *
     * @return array{
     *     passed: bool,
     *     evidence: list<array{
     *         command: string,
     *         exit_code: ?int,
     *         passed: bool
     *     }>
     * }
     */
    private function runConfiguredCommands(string $path): array
    {
        $evidence = [];

        foreach ($this->safeConfiguredCommands() as $command) {
            $result = Process::path($path)
                ->timeout((int) config('aios.execution_timeout'))
                ->run(
                    preg_split('/\s+/', trim($command)) ?: [],
                );

            $evidence[] = [
                'command' => $command,
                'exit_code' => $result->exitCode(),
                'passed' => $result->successful(),
            ];

            if ($result->failed()) {
                return [
                    'passed' => false,
                    'evidence' => $evidence,
                ];
            }
        }

        return [
            'passed' => true,
            'evidence' => $evidence,
        ];
    }

    /**
     * Return only explicitly allowlisted recovery validation commands.
     *
     * @return list<string>
     */
    private function safeConfiguredCommands(): array
    {
        $allowedExecutables = [
            'php',
            'composer',
            'vendor/bin/pest',
            './vendor/bin/pest',
            'vendor/bin/phpstan',
            './vendor/bin/phpstan',
            'vendor/bin/pint',
            './vendor/bin/pint',
        ];

        return array_values(
            collect((array) config('aios.recovery_validation_commands'))
                ->filter(
                    fn (mixed $command): bool => is_string($command)
                        && preg_match(
                            '~^[A-Za-z0-9_./:=+,@-]+(?: [A-Za-z0-9_./:=+,@-]+)*$~',
                            $command,
                        ) === 1
                        && in_array(
                            (
                                preg_split(
                                    '/\s+/',
                                    trim($command),
                                ) ?: ['']
                            )[0],
                            $allowedExecutables,
                            true,
                        ),
                )
                ->values()
                ->all(),
        );
    }

    /**
     * Determine whether a Recovery Engineer change targets forbidden credential material.
     */
    private function isForbiddenFile(string $file): bool
    {
        $filename = basename($file);

        return $filename === '.env'
            || (
                Str::startsWith($filename, '.env.')
                && $filename !== '.env.example'
            )
            || preg_match(
                '/^(?:id_rsa|.*\.(?:pem|key))$/',
                $filename,
            ) === 1;
    }

    /**
     * Execute a Git command expected to return a null-delimited file list.
     *
     * @param  array<int, string>  $command
     * @return list<string>|null
     */
    private function files(string $path, array $command): ?array
    {
        $result = $this->run($path, $command);

        if (! $result['successful']) {
            return null;
        }

        return $this->normalize(
            explode("\0", $result['output']),
        );
    }

    /**
     * Execute one bounded Git or validation command without throwing through recovery.
     *
     * @param  array<int, string>  $command
     * @return array{
     *     successful: bool,
     *     output: string,
     *     exit_code: ?int
     * }
     */
    private function run(string $path, array $command): array
    {
        try {
            $result = Process::path($path)->run($command);

            return [
                'successful' => $result->successful(),
                'output' => $result->output(),
                'exit_code' => $result->exitCode(),
            ];
        } catch (Throwable) {
            return [
                'successful' => false,
                'output' => '',
                'exit_code' => null,
            ];
        }
    }

    /**
     * Normalize one deterministic repository-relative file set.
     *
     * @param  array<int, string>  $files
     * @return list<string>
     */
    private function normalize(array $files): array
    {
        $files = array_values(
            array_unique(
                array_filter(
                    $files,
                    fn (string $file): bool => $file !== '',
                ),
            ),
        );

        sort($files, SORT_STRING);

        return $files;
    }
}
