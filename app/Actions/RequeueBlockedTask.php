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
        abort_if(
            $task->planningEscalations()->whereIn('status', ['pending', 'running'])->exists(),
            409,
            'This task has a pending Project Manager planning revision and cannot be manually requeued.',
        );

        $latestBlockDecision = $task->auditEvents()
            ->whereIn('event_type', ['review.retry_exhausted', 'task.coder_retry_exhausted', 'task.no_progress_detected', 'task.contract_drift_detected', 'task.review_no_progress_blocked'])
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
            'task.contract_drift_detected' => $this->isReviewerContextOnlyContractDrift($latestPayload)
                ? TaskStatus::ReadyForReview
                : TaskStatus::ChangesRequired,
            'task.review_no_progress_blocked' => TaskStatus::ChangesRequired,
            'task.no_progress_detected' => $operation === 'reviewer'
                ? TaskStatus::ReadyForReview
                : TaskStatus::ChangesRequired,
            default => TaskStatus::ChangesRequired,
        };
        $task = $this->workflow->transition($task, $status);
        $this->audit->record('task.requeued', [
            'reason' => $this->requeueReason($latestBlockDecision?->event_type, $latestPayload),
            'status' => $status->value,
        ], $task->project, $task);

        return $task;
    }

    /** @param array<string, mixed> $payload */
    private function isReviewerContextOnlyContractDrift(array $payload): bool
    {
        if (($payload['operation'] ?? null) !== 'reviewer' || ! is_array($payload['changed_inputs'] ?? null)) {
            return false;
        }

        $changedInputs = $payload['changed_inputs'];

        return $changedInputs !== [] && collect($changedInputs)->every(
            fn (mixed $input): bool => is_string($input) && str_starts_with($input, 'repository_documents:'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function requeueReason(?string $eventType, array $payload): string
    {
        if ($eventType !== 'task.contract_drift_detected') {
            return 'manual recovery';
        }

        return $this->isReviewerContextOnlyContractDrift($payload)
            ? 'manual reviewer context rebase'
            : 'manual contract rebase';
    }
}
