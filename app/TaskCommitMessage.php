<?php

namespace App;

use App\Models\Task;

class TaskCommitMessage
{
    /**
     * Build the AIOS-owned Conventional Commit subject for one Task.
     */
    public function for(Task $task): string
    {
        $workType = $task->getRawOriginal('work_type');
        $workType = is_string($workType) ? TaskWorkType::tryFrom($workType) : null;

        $type = match ($workType) {
            TaskWorkType::Bug => 'fix',
            TaskWorkType::Feature, TaskWorkType::Enhancement => 'feat',
            default => 'chore',
        };

        return "{$type}({$task->key}): {$task->title}";
    }
}
