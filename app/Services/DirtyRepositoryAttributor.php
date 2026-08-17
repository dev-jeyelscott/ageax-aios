<?php

namespace App\Services;

use App\Models\Project;
use App\Models\TaskAttempt;
use App\TaskStatus;

/**
 * Explains a dirty working tree by matching it to another task's own abandoned attempt, so the
 * Workflow Recovery Engineer can resume the originating task instead of escalating every task
 * that happens to claim the coder role next. Attribution never touches the working tree itself;
 * it only reads TaskAttempt evidence already persisted by RunCoderTask.
 */
class DirtyRepositoryAttributor
{
    /**
     * @param  array{staged_files: array<int, string>, unstaged_files: array<int, string>, untracked_files: array<int, string>, head_sha: ?string}  $gitState
     */
    public function attribute(Project $project, array $gitState): ?TaskAttempt
    {
        if ($gitState['head_sha'] === null) {
            return null;
        }

        $dirtyFiles = $this->normalize([...$gitState['staged_files'], ...$gitState['unstaged_files'], ...$gitState['untracked_files']]);

        if ($dirtyFiles === []) {
            return null;
        }

        $candidates = TaskAttempt::query()
            ->whereHas('task', fn ($query) => $query->whereBelongsTo($project)->whereIn('status', [TaskStatus::Blocked, TaskStatus::Failed, TaskStatus::Interrupted]))
            ->whereIn('status', ['failed', 'interrupted'])
            ->whereNull('commit_sha')
            ->whereNotNull('base_sha')
            ->where('base_sha', $gitState['head_sha'])
            ->get()
            ->filter(fn (TaskAttempt $attempt): bool => $this->accountsForAllDirtyFiles($dirtyFiles, $attempt));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @param  array<int, string>  $dirtyFiles
     */
    private function accountsForAllDirtyFiles(array $dirtyFiles, TaskAttempt $attempt): bool
    {
        $changedFiles = $attempt->getAttribute('changed_files');
        $expectedFiles = $this->normalize(is_array($changedFiles) ? $changedFiles : []);

        // A prior attempt's own file set only needs to cover the current dirty state, not match it
        // exactly: the agent may have already staged/committed part of its own diff before it was
        // interrupted, leaving a strict subset of its expected changes still dirty.
        return array_diff($dirtyFiles, $expectedFiles) === [];
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<int, string>
     */
    private function normalize(array $files): array
    {
        $normalized = [];

        foreach ($files as $file) {
            if (! is_string($file) || $file === '') {
                continue;
            }

            $normalized[] = $file;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }
}
