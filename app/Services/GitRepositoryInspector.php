<?php

namespace App\Services;

use App\Exceptions\UnsafeProjectPath;
use Illuminate\Support\Facades\Process;

class GitRepositoryInspector
{
    public function __construct(private WorkspacePathResolver $paths) {}

    /**
     * @return array{
     *     inspectable: bool,
     *     clean: bool,
     *     head_sha: ?string,
     *     index_files: array<int, string>,
     *     working_tree_files: array<int, string>,
     *     untracked_files: array<int, string>,
     *     error: ?string
     * }
     */
    public function inspect(string $projectPath): array
    {
        try {
            $path = $this->paths->assertProjectPath($projectPath);
        } catch (UnsafeProjectPath $exception) {
            return $this->unavailableState($exception->getMessage());
        }

        if (! is_dir($path)) {
            return $this->unavailableState('Project directory is unavailable.');
        }

        $head = Process::path($path)->run(['git', 'rev-parse', '--verify', 'HEAD']);
        $index = Process::path($path)->run(['git', 'diff', '--cached', '--no-renames', '--name-only', '-z', '--']);
        $workingTree = Process::path($path)->run(['git', 'diff', '--no-renames', '--name-only', '-z', '--']);
        $untracked = Process::path($path)->run(['git', 'ls-files', '--others', '--exclude-standard', '-z']);

        $inspectable = $head->successful() && $index->successful() && $workingTree->successful() && $untracked->successful();
        $headSha = $head->successful() ? trim($head->output()) : null;
        $indexFiles = $index->successful() ? $this->pathsFromOutput($index->output()) : [];
        $untrackedFiles = $untracked->successful() ? $this->pathsFromOutput($untracked->output()) : [];
        $workingTreeFiles = $workingTree->successful()
            ? $this->normalizePaths([...$this->pathsFromOutput($workingTree->output()), ...$untrackedFiles])
            : $untrackedFiles;

        return [
            'inspectable' => $inspectable,
            'clean' => $inspectable && $indexFiles === [] && $workingTreeFiles === [],
            'head_sha' => $headSha,
            'index_files' => $indexFiles,
            'working_tree_files' => $workingTreeFiles,
            'untracked_files' => $untrackedFiles,
            'error' => $inspectable ? null : ($head->failed() ? 'Repository HEAD is unavailable.' : 'Git repository inspection failed.'),
        ];
    }

    /** @return array<int, string>|null */
    public function changedFilesFromBase(string $projectPath, string $baseSha): ?array
    {
        try {
            $path = $this->paths->assertProjectPath($projectPath);
        } catch (UnsafeProjectPath) {
            return null;
        }

        if (! is_dir($path)) {
            return null;
        }

        $head = Process::path($path)->run(['git', 'rev-parse', '--verify', 'HEAD']);
        if ($head->failed() || trim($head->output()) !== $baseSha) {
            return null;
        }

        $tracked = Process::path($path)->run(['git', 'diff', '--no-renames', '--name-only', '-z', $baseSha, '--']);
        $untracked = Process::path($path)->run(['git', 'ls-files', '--others', '--exclude-standard', '-z']);
        if ($tracked->failed() || $untracked->failed()) {
            return null;
        }

        return $this->normalizePaths([
            ...$this->pathsFromOutput($tracked->output()),
            ...$this->pathsFromOutput($untracked->output()),
        ]);
    }

    /** @return array<int, string> */
    private function pathsFromOutput(string $output): array
    {
        return $this->normalizePaths(explode("\0", $output));
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function normalizePaths(array $paths): array
    {
        return collect($paths)
            ->filter(fn (string $path): bool => $path !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     inspectable: false,
     *     clean: false,
     *     head_sha: null,
     *     index_files: array<int, string>,
     *     working_tree_files: array<int, string>,
     *     untracked_files: array<int, string>,
     *     error: string
     * }
     */
    private function unavailableState(string $error): array
    {
        return [
            'inspectable' => false,
            'clean' => false,
            'head_sha' => null,
            'index_files' => [],
            'working_tree_files' => [],
            'untracked_files' => [],
            'error' => $error,
        ];
    }
}
