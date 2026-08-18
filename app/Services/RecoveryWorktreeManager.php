<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates and destroys a disposable Git worktree of the AIOS repository itself so that the
 * Workflow Recovery Engineer's harness (Codex or Claude Code) never receives Edit/Write/Bash
 * access to the live AIOS checkout or its database. The harness may modify only this isolated
 * worktree; AIOS alone (see WorkflowRecoveryEngine) inspects the exact resulting changes, applies
 * them into the live repository's working tree, and independently validates/commits them through
 * the existing RecoveryRepositoryLifecycle. The worktree is always destroyed afterward.
 */
class RecoveryWorktreeManager
{
    public function create(string $repositoryPath, string $baseSha): string
    {
        $worktreePath = rtrim(sys_get_temp_dir(), '/').'/aios-recovery-worktree-'.Str::uuid();

        $result = Process::path($repositoryPath)->run(['git', 'worktree', 'add', '--detach', $worktreePath, $baseSha]);

        if (! $result->successful()) {
            throw new RuntimeException('Could not create an isolated recovery worktree: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $worktreePath;
    }

    public function destroy(string $repositoryPath, string $worktreePath): void
    {
        Process::path($repositoryPath)->run(['git', 'worktree', 'remove', '--force', $worktreePath]);

        if (is_dir($worktreePath)) {
            File::deleteDirectory($worktreePath);
        }

        Process::path($repositoryPath)->run(['git', 'worktree', 'prune']);
    }
}
