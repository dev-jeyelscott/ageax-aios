<?php

namespace App\Services;

use App\Exceptions\UnsafeProjectPath;
use App\Models\Task;
use App\Models\TaskAttempt;
use Illuminate\Support\Facades\File;
use LogicException;
use RuntimeException;

/**
 * Resolves and owns one isolated AIOS-managed Git worktree per Coder Task attempt.
 *
 * The worktree path and base SHA come only from durable AIOS state. Agents and harnesses never
 * choose workspace locations or Git lifecycle commands.
 */
class TaskWorktreeManager
{
    private const string RootDirectory = '.aios-task-worktrees';

    public function __construct(
        private WorkspacePathResolver $paths,
        private IsolatedGitWorktreeManager $worktrees,
    ) {}

    /**
     * Create or safely reuse the exact worktree assigned to one durable Task attempt.
     */
    public function acquire(
        Task $task,
        TaskAttempt $attempt,
    ): string {
        $this->assertAttemptOwnership($task, $attempt);
        $task->loadMissing('project');

        $repositoryPath = $this->paths->assertProjectPath(
            (string) $task->project->path,
        );

        $baseSha = strtolower(
            trim((string) $attempt->base_sha),
        );

        if ($baseSha === '') {
            throw new RuntimeException(
                'Task worktree creation requires a persisted attempt base SHA.',
            );
        }

        $worktreePath = $this->pathFor(
            $task,
            $attempt,
        );

        if (
            $this->worktrees->matches(
                $repositoryPath,
                $worktreePath,
                $baseSha,
            )
        ) {
            return $this->paths->resolve(
                $this->relativePath($task, $attempt),
                mustExist: true,
            );
        }

        $this->worktrees->destroy(
            $repositoryPath,
            $worktreePath,
        );

        $this->worktrees->createDetached(
            $repositoryPath,
            $worktreePath,
            $baseSha,
        );

        if (
            ! $this->worktrees->matches(
                $repositoryPath,
                $worktreePath,
                $baseSha,
            )
        ) {
            $this->worktrees->destroy(
                $repositoryPath,
                $worktreePath,
            );

            throw new RuntimeException(
                'AIOS could not verify the isolated Task worktree after creation.',
            );
        }

        return $this->paths->resolve(
            $this->relativePath($task, $attempt),
            mustExist: true,
        );
    }

    /**
     * Remove only the worktree deterministically assigned to the supplied Task attempt.
     */
    public function release(
        Task $task,
        TaskAttempt $attempt,
    ): void {
        $this->assertAttemptOwnership($task, $attempt);
        $task->loadMissing('project');

        $repositoryPath = $this->paths->assertProjectPath(
            (string) $task->project->path,
        );

        $this->worktrees->destroy(
            $repositoryPath,
            $this->pathFor($task, $attempt),
        );
    }

    /**
     * Resolve the AIOS-owned execution directory for one Task attempt inside the configured workspace root.
     */
    public function pathFor(
        Task $task,
        TaskAttempt $attempt,
    ): string {
        $this->assertAttemptOwnership($task, $attempt);
        $this->ensureManagedRoot();

        return $this->paths->resolve(
            $this->relativePath($task, $attempt),
        );
    }

    /**
     * Ensure the shared Task-worktree parent is a real directory inside WorkspacePathResolver authority.
     */
    private function ensureManagedRoot(): string
    {
        $candidate = $this->paths->resolve(
            self::RootDirectory,
        );

        if (is_link($candidate)) {
            throw new UnsafeProjectPath(
                'The AIOS Task worktree root must not be a symbolic link.',
            );
        }

        if (! file_exists($candidate)) {
            File::ensureDirectoryExists($candidate);
        }

        $resolved = $this->paths->resolve(
            self::RootDirectory,
            mustExist: true,
        );

        if (! is_dir($resolved)) {
            throw new RuntimeException(
                'The AIOS Task worktree root must be a directory.',
            );
        }

        return $resolved;
    }

    /**
     * Build a flat, deterministic relative worktree path from durable database identifiers only.
     */
    private function relativePath(
        Task $task,
        TaskAttempt $attempt,
    ): string {
        return self::RootDirectory
            .DIRECTORY_SEPARATOR
            .'project-'.(int) $task->project_id
            .'-task-'.(int) $task->getKey()
            .'-attempt-'.(int) $attempt->getKey();
    }

    /**
     * Reject cross-Task attempt usage so one Task cannot ask AIOS to acquire or remove another Task's worktree.
     */
    private function assertAttemptOwnership(
        Task $task,
        TaskAttempt $attempt,
    ): void {
        if (
            ! $task->exists
            || ! $attempt->exists
            || (int) $attempt->task_id
                !== (int) $task->getKey()
        ) {
            throw new LogicException(
                'Task worktree operations require a persisted attempt owned by the same Task.',
            );
        }
    }
}
