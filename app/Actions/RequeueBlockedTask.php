<?php

namespace App\Actions;

use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\TaskWorkflow;
use App\TaskStatus;

class RequeueBlockedTask
{
    public function __construct(private TaskWorkflow $workflow, private AuditLogger $audit) {}

    public function handle(Task $task): Task
    {
        abort_unless(TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Blocked, 409, 'Only blocked tasks may be requeued.');

        $task = $this->workflow->transition($task, TaskStatus::ChangesRequired);
        $this->audit->record('task.requeued', ['reason' => 'manual recovery'], $task->project, $task);

        return $task;
    }
}
