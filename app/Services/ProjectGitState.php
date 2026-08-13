<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Throwable;

class ProjectGitState
{
    public function __construct(private WorkspacePathResolver $paths) {}

    /**
     * @return array{
     *     inspectable: bool,
     *     clean: bool,
     *     head_sha: ?string,
     *     base_sha: ?string,
     *     staged_files: array<int, string>,
     *     unstaged_files: array<int, string>,
     *     untracked_files: array<int, string>,
     *     errors: array<int, string>
     * }
     */
    public function inspect(string $projectPath): array
    {
        $projectPath = $this->paths->assertProjectPath($projectPath);

        if (! is_dir($projectPath)) {
            return $this->unavailable('The project directory does not exist.');
        }

        try {
            $inside = Process::path($projectPath)->run(['git', 'rev-parse', '--is-inside-work-tree']);
        } catch (Throwable $throwable) {
            return $this->unavailable($throwable->getMessage());
        }

        if ($inside->failed() || trim($inside->output()) !== 'true') {
            return $this->unavailable('The project directory is not a Git work tree.');
        }

        $head = $this->result($projectPath, ['git', 'rev-parse', '--verify', 'HEAD']);
        $headSha = $head['successful'] ? trim($head['output']) : null;

        if (! $head['successful']) {
            $symbolicHead = $this->result($projectPath, ['git', 'symbolic-ref', '-q', 'HEAD']);

            if (! $symbolicHead['successful']) {
                return $this->unavailable('Git could not inspect HEAD.');
            }
        }

        $baseSha = $headSha ?? $this->emptyTreeSha($projectPath);
        $staged = $this->files($projectPath, ['git', 'diff', '--cached', '--name-only', '--no-renames', '-z', '--']);
        $unstaged = $this->files($projectPath, ['git', 'diff', '--name-only', '--no-renames', '-z', '--']);
        $untracked = $this->files($projectPath, ['git', 'ls-files', '--others', '--exclude-standard', '-z', '--']);
        $errors = [];

        if ($baseSha === null) {
            $errors[] = 'Git could not determine a clean base object.';
        }
        if ($staged === null) {
            $errors[] = 'Git could not inspect the index.';
        }
        if ($unstaged === null) {
            $errors[] = 'Git could not inspect tracked working-tree changes.';
        }
        if ($untracked === null) {
            $errors[] = 'Git could not inspect untracked working-tree files.';
        }

        $inspectable = $errors === [];
        $staged ??= [];
        $unstaged ??= [];
        $untracked ??= [];

        return [
            'inspectable' => $inspectable,
            'clean' => $inspectable && $staged === [] && $unstaged === [] && $untracked === [],
            'head_sha' => $headSha,
            'base_sha' => $baseSha,
            'staged_files' => $staged,
            'unstaged_files' => $unstaged,
            'untracked_files' => $untracked,
            'errors' => $errors,
        ];
    }

    /** @return array<int, string>|null */
    public function changedFilesFromBase(string $projectPath, string $baseSha): ?array
    {
        $projectPath = $this->paths->assertProjectPath($projectPath);
        $staged = $this->files($projectPath, ['git', 'diff', '--cached', '--name-only', '--no-renames', '-z', $baseSha, '--']);
        $unstaged = $this->files($projectPath, ['git', 'diff', '--name-only', '--no-renames', '-z', '--']);
        $untracked = $this->files($projectPath, ['git', 'ls-files', '--others', '--exclude-standard', '-z', '--']);

        if ($staged === null || $unstaged === null || $untracked === null) {
            return null;
        }

        return $this->normalize([...$staged, ...$unstaged, ...$untracked]);
    }

    /** @return array<int, string>|null */
    public function stagedFiles(string $projectPath): ?array
    {
        $projectPath = $this->paths->assertProjectPath($projectPath);

        return $this->files($projectPath, ['git', 'diff', '--cached', '--name-only', '--no-renames', '-z', '--']);
    }

    public function baseMatchesCurrentHead(string $projectPath, string $baseSha): bool
    {
        $state = $this->inspect($projectPath);

        return $state['inspectable'] && $state['base_sha'] !== null && hash_equals($baseSha, $state['base_sha']);
    }

    /**
     * @param  array<int, string>  $expectedFiles
     */
    public function matchesExpectedChanges(string $projectPath, string $baseSha, array $expectedFiles): bool
    {
        $actualFiles = $this->changedFilesFromBase($projectPath, $baseSha);

        return $actualFiles !== null && $actualFiles === $this->normalize($expectedFiles);
    }

    /** @param array<int, string> $command @return array<int, string>|null */
    private function files(string $projectPath, array $command): ?array
    {
        $result = $this->result($projectPath, $command);

        if (! $result['successful']) {
            return null;
        }

        return $this->normalize(explode("\0", $result['output']));
    }

    private function emptyTreeSha(string $projectPath): ?string
    {
        $result = $this->result($projectPath, ['git', 'hash-object', '-t', 'tree', '/dev/null']);

        return $result['successful'] ? trim($result['output']) : null;
    }

    /** @param array<int, string> $command @return array{successful: bool, output: string} */
    private function result(string $projectPath, array $command): array
    {
        try {
            $result = Process::path($projectPath)->run($command);

            return ['successful' => $result->successful(), 'output' => $result->output()];
        } catch (Throwable) {
            return ['successful' => false, 'output' => ''];
        }
    }

    /** @param array<int, string> $files @return array<int, string> */
    private function normalize(array $files): array
    {
        $files = array_values(array_unique(array_filter($files, fn (string $file): bool => $file !== '')));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array{
     *     inspectable: false,
     *     clean: false,
     *     head_sha: null,
     *     base_sha: null,
     *     staged_files: array<int, string>,
     *     unstaged_files: array<int, string>,
     *     untracked_files: array<int, string>,
     *     errors: array<int, string>
     * }
     */
    private function unavailable(string $error): array
    {
        return [
            'inspectable' => false,
            'clean' => false,
            'head_sha' => null,
            'base_sha' => null,
            'staged_files' => [],
            'unstaged_files' => [],
            'untracked_files' => [],
            'errors' => [$error],
        ];
    }
}

