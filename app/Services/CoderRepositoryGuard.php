<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAttempt;

class CoderRepositoryGuard
{
    public function __construct(private ProjectGitState $git) {}

    /**
     * @return array{
     *     allowed: bool,
     *     mode: 'normal'|'recovery'|'blocked',
     *     base_sha: ?string,
     *     recovery_attempt: ?TaskAttempt,
     *     state: array{
     *         inspectable: bool,
     *         clean: bool,
     *         head_sha: ?string,
     *         base_sha: ?string,
     *         staged_files: array<int, string>,
     *         unstaged_files: array<int, string>,
     *         untracked_files: array<int, string>,
     *         errors: array<int, string>
     *     }
     * }
     */
    public function inspect(Task $task): array
    {
        $task->loadMissing('project');
        $state = $this->git->inspect($task->project->path);

        if ($state['clean'] && $state['base_sha'] !== null) {
            return [
                'allowed' => true,
                'mode' => 'normal',
                'base_sha' => $state['base_sha'],
                'recovery_attempt' => null,
                'state' => $state,
            ];
        }

        $attempt = $this->latestResumableAttempt($task);

        if ($state['inspectable'] && $attempt !== null && $this->matchesAttempt($task, $attempt)) {
            return [
                'allowed' => true,
                'mode' => 'recovery',
                'base_sha' => $attempt->base_sha,
                'recovery_attempt' => $attempt,
                'state' => $state,
            ];
        }

        return [
            'allowed' => false,
            'mode' => 'blocked',
            'base_sha' => $state['base_sha'],
            'recovery_attempt' => null,
            'state' => $state,
        ];
    }

    private function latestResumableAttempt(Task $task): ?TaskAttempt
    {
        $attempt = $task->attempts()->orderByDesc('number')->first();

        if ($attempt === null || ! in_array($attempt->status, ['failed', 'interrupted'], true) || $attempt->commit_sha !== null || blank($attempt->base_sha)) {
            return null;
        }

        return $attempt;
    }

    private function matchesAttempt(Task $task, TaskAttempt $attempt): bool
    {
        $baseSha = (string) $attempt->base_sha;

        if (! $this->git->baseMatchesCurrentHead($task->project->path, $baseSha)) {
            return false;
        }

        if ($attempt->status === 'interrupted') {
            return $this->git->changedFilesFromBase($task->project->path, $baseSha) !== null;
        }

        return $this->git->matchesExpectedChanges($task->project->path, $baseSha, $attempt->changed_files ?? []);
    }
}

