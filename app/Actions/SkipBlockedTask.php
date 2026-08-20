<?php

namespace App\Actions;

use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\TaskWorkflow;
use App\TaskStatus;

class SkipBlockedTask
{
    public function __construct(private TaskWorkflow $workflow, private AuditLogger $audit) {}

    public function handle(Task $task, string $reason): Task
    {
        abort_unless(TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Blocked, 409, 'Only blocked tasks may be skipped.');

        $dependents = $task->dependents()->get(['tasks.id', 'tasks.key', 'tasks.title'])->map(fn (Task $dependent): array => $dependent->only(['id', 'key', 'title']))->all();

        $task = $this->workflow->transition($task, TaskStatus::Cancelled);

        $this->audit->record('task.skipped', [
            'reason' => $reason,
            'dependents' => $dependents,
        ], $task->project, $task);

        return $task;
    }
}
