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

        $latestBlockDecision = $task->auditEvents()
            ->whereIn('event_type', ['review.retry_exhausted', 'task.coder_retry_exhausted', 'task.no_progress_detected'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
        $latestPayload = $latestBlockDecision === null
            ? []
            : json_decode((string) $latestBlockDecision->getRawOriginal('payload'), true);
        $operation = is_array($latestPayload) && is_string($latestPayload['operation'] ?? null)
            ? $latestPayload['operation']
            : null;
        $status = match ($latestBlockDecision?->event_type) {
            'review.retry_exhausted' => TaskStatus::ReadyForReview,
            'task.no_progress_detected' => $operation === 'reviewer'
                ? TaskStatus::ReadyForReview
                : TaskStatus::ChangesRequired,
            default => TaskStatus::ChangesRequired,
        };
        $task = $this->workflow->transition($task, $status);
        $this->audit->record('task.requeued', ['reason' => 'manual recovery', 'status' => $status->value], $task->project, $task);

        return $task;
    }
}
