<?php

namespace App\Actions;

use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\TaskWorkflow;
use App\TaskStatus;

class RestoreSkippedTask
{
    public function __construct(private TaskWorkflow $workflow, private AuditLogger $audit) {}

    public function handle(Task $task): Task
    {
        abort_unless(
            TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Cancelled
                && $task->auditEvents()->where('event_type', 'task.skipped')->exists(),
            409,
            'Only explicitly skipped tasks may be restored.',
        );

        $task = $this->workflow->transition($task, TaskStatus::ChangesRequired);

        $this->audit->record('task.restored', [
            'reason' => 'operator_restored_skipped_task',
            'status' => TaskStatus::from($task->getRawOriginal('status'))->value,
        ], $task->project, $task);

        return $task;
    }
}
