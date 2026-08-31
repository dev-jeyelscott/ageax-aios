<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Owns the low-level Git worktree lifecycle used by AIOS-controlled isolation boundaries.
 *
 * Callers remain responsible for authorizing the repository and worktree paths before invoking
 * this service. Agents and harnesses never receive authority to choose Git lifecycle commands.
 */
class IsolatedGitWorktreeManager
{
    /**
     * Create a detached worktree at one exact Git commit object.
     */
    public function createDetached(string $repositoryPath, string $worktreePath, string $baseSha): void
    {
        $baseSha = $this->normalizeExactObjectId($baseSha);

        if (file_exists($worktreePath) || is_link($worktreePath)) {
            throw new RuntimeException('Could not create an isolated worktree because its AIOS-managed path already exists.');
        }

        File::ensureDirectoryExists(dirname($worktreePath));

        try {
            $result = Process::path($repositoryPath)->run([
                'git',
                'worktree',
                'add',
                '--detach',
                $worktreePath,
                $baseSha,
            ]);
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Could not create an isolated Git worktree.',
                0,
                $throwable,
            );
        }

        if (! $result->successful()) {
            throw new RuntimeException(
                'Could not create an isolated Git worktree: '.trim(
                    $result->errorOutput() ?: $result->output(),
                ),
            );
        }
    }

    /**
     * Symlink untracked shared dependency directories (vendor, node_modules) from the source
     * repository into an isolated worktree, since `git worktree add` only materializes tracked
     * files and these directories are gitignored. Idempotent: safe to call on every acquire.
     */
    public function linkSharedDependencies(string $repositoryPath, string $worktreePath): void
    {
        foreach (['vendor', 'node_modules'] as $directory) {
            $target = $repositoryPath.DIRECTORY_SEPARATOR.$directory;
            $link = $worktreePath.DIRECTORY_SEPARATOR.$directory;

            if (! is_dir($target) || is_link($target)) {
                continue;
            }

            if (is_link($link)) {
                if (readlink($link) === $target) {
                    continue;
                }

                File::delete($link);
            } elseif (file_exists($link)) {
                continue;
            }

            symlink($target, $link);
        }
    }

    /**
     * Determine whether an existing worktree belongs to the repository and still points at the exact base SHA.
     */
    public function matches(string $repositoryPath, string $worktreePath, string $baseSha): bool
    {
        if (
            ! is_dir($repositoryPath)
            || ! is_dir($worktreePath)
            || is_link($worktreePath)
        ) {
            return false;
        }

        try {
            $baseSha = $this->normalizeExactObjectId($baseSha);

            $head = Process::path($worktreePath)->run([
                'git',
                'rev-parse',
                '--verify',
                'HEAD',
            ]);
        } catch (Throwable) {
            return false;
        }

        if (
            ! $head->successful()
            || ! hash_equals($baseSha, trim($head->output()))
        ) {
            return false;
        }

        $repositoryGitDirectory = $this->commonGitDirectory(
            $repositoryPath,
        );

        $worktreeGitDirectory = $this->commonGitDirectory(
            $worktreePath,
        );

        return $repositoryGitDirectory !== null
            && $worktreeGitDirectory !== null
            && hash_equals(
                $repositoryGitDirectory,
                $worktreeGitDirectory,
            );
    }

    /**
     * Remove a worktree, any partial filesystem residue, and stale Git worktree metadata idempotently.
     */
    public function destroy(string $repositoryPath, string $worktreePath): void
    {
        if (! is_link($worktreePath) && ! is_file($worktreePath)) {
            try {
                Process::path($repositoryPath)->run([
                    'git',
                    'worktree',
                    'remove',
                    '--force',
                    $worktreePath,
                ]);
            } catch (Throwable) {
                // Filesystem cleanup and prune below remain authoritative
                // for idempotent recovery.
            }
        }

        $this->removeFilesystemEntry($worktreePath);
        $this->prune($repositoryPath);
    }

    /**
     * Resolve the repository's shared Git metadata directory for worktree ownership comparison.
     */
    private function commonGitDirectory(string $path): ?string
    {
        try {
            $result = Process::path($path)->run([
                'git',
                'rev-parse',
                '--git-common-dir',
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $gitDirectory = trim($result->output());

        if ($gitDirectory === '') {
            return null;
        }

        $candidate = str_starts_with(
            $gitDirectory,
            DIRECTORY_SEPARATOR,
        )
            ? $gitDirectory
            : $path.DIRECTORY_SEPARATOR.$gitDirectory;

        $resolved = realpath($candidate);

        return $resolved === false
            ? null
            : rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Remove only the worktree path itself without following symlinks to another workspace.
     */
    private function removeFilesystemEntry(string $worktreePath): void
    {
        if (is_link($worktreePath) || is_file($worktreePath)) {
            File::delete($worktreePath);

            return;
        }

        if (is_dir($worktreePath)) {
            File::deleteDirectory($worktreePath);
        }
    }

    /**
     * Prune stale Git worktree registration without allowing cleanup failure to break idempotency.
     */
    private function prune(string $repositoryPath): void
    {
        try {
            Process::path($repositoryPath)->run([
                'git',
                'worktree',
                'prune',
            ]);
        } catch (Throwable) {
            // Repeated cleanup may retry prune after the repository
            // becomes inspectable again.
        }
    }

    /**
     * Require a literal SHA-1 or SHA-256 object ID instead of an Agent-controlled ref or revision expression.
     */
    private function normalizeExactObjectId(string $baseSha): string
    {
        $baseSha = strtolower(trim($baseSha));

        if (
            preg_match(
                '/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/',
                $baseSha,
            ) !== 1
        ) {
            throw new RuntimeException(
                'Isolated worktrees require an exact Git commit SHA.',
            );
        }

        return $baseSha;
    }
}
