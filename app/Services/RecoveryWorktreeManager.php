<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Creates and destroys a disposable Git worktree of the AIOS repository itself so that the
 * Workflow Recovery Engineer's harness never receives edit access to the live AIOS checkout.
 *
 * Recovery-specific path ownership stays here while the low-level Git worktree lifecycle is
 * shared with isolated Coder Task worktrees through IsolatedGitWorktreeManager.
 */
class RecoveryWorktreeManager
{
    public function __construct(
        private IsolatedGitWorktreeManager $worktrees,
    ) {}

    /**
     * Create a detached Recovery Engineer worktree from the exact AIOS repository base SHA.
     */
    public function create(string $repositoryPath, string $baseSha): string
    {
        $worktreePath = rtrim(
            sys_get_temp_dir(),
            DIRECTORY_SEPARATOR,
        )
            .DIRECTORY_SEPARATOR
            .'aios-recovery-worktree-'.Str::uuid();

        $this->worktrees->createDetached(
            $repositoryPath,
            $worktreePath,
            $baseSha,
        );

        return $worktreePath;
    }

    /**
     * Remove the disposable Recovery Engineer worktree idempotently after completion or failure.
     */
    public function destroy(
        string $repositoryPath,
        string $worktreePath,
    ): void {
        $this->worktrees->destroy(
            $repositoryPath,
            $worktreePath,
        );
    }
}
