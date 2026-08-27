<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\Models\TaskPlanningEscalation;
use App\RecoveryIncidentStatus;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;

/**
 * Detection half of the Workflow Recovery Engineer. Runs every five minutes (see
 * App\Console\Commands\RecoverWorkflows) and looks only for actionable, already-persisted
 * evidence of an abnormal workflow condition; it never infers failure from elapsed time alone.
 *
 * Two distinct triggers are handled:
 *  - Active-execution staleness (expired worker leases, stale roadmap attempts): delegated to
 *    the existing, already-tested StaleWorkerRecovery, wrapped in a single resolved incident per
 *    scan so the recovery remains auditable without duplicating that service's logic.
 *  - Terminal stuck tasks (blocked/interrupted/failed, persisted past a small anti-flake floor):
 *    a durable, open RecoveryIncident is created (at most one per task at a time) for the
 *    Workflow Recovery Engineer to diagnose and, where safe, repair. Explicit no-progress blocks
 *    are excluded because their deterministic evidence already requires operator intervention;
 *    creating a recovery incident would only launch another unnecessary AI execution.
 */
class WorkflowRecoveryScanner
{
    private const array TerminalStuckStatuses = [TaskStatus::Blocked, TaskStatus::Interrupted, TaskStatus::Failed];

    /** Block reasons that are purely a symptom of a dirty repository, and the status to resume into once it's clean. */
    private const array AutoUnblockableBlockReasons = [
        'task.blocked_dirty_repository' => TaskStatus::ChangesRequired,
    ];

    public function __construct(private StaleWorkerRecovery $staleWorkerRecovery, private AuditLogger $audit, private ProjectGitState $git, private TaskWorkflow $workflow, private TaskPlanningDefectPreflight $planningPreflight) {}

    public function scan(Project $project): void
    {
        $this->recoverStaleExecutions($project);
        $this->resolveObsoletePlanningEscalations($project);
        $this->autoUnblockCleanRepositories($project);
        $this->detectStuckTasks($project);
    }

    /**
     * A planning escalation records the defect that existed when a Coder attempt was claimed.
     * It must not indefinitely block a Task after an approved planning change has made that
     * contract valid. Only a Task whose latest blocking decision was that escalation may resume;
     * a later reviewer, validation, Git, or no-progress block remains authoritative.
     */
    private function resolveObsoletePlanningEscalations(Project $project): void
    {
        Task::query()
            ->whereBelongsTo($project)
            ->notCleared()
            ->where('status', TaskStatus::Blocked)
            ->whereHas('planningEscalations', fn ($query) => $query->where('status', 'blocked'))
            ->get()
            ->each(function (Task $task) use ($project): void {
                DB::transaction(function () use ($project, $task): void {
                    $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
                    if (TaskStatus::from($lockedTask->getRawOriginal('status')) !== TaskStatus::Blocked
                        || $lockedTask->planningEscalations()->whereIn('status', ['pending', 'running'])->exists()) {
                        return;
                    }

                    $latestBlockDecision = $lockedTask->auditEvents()
                        ->where(function ($query): void {
                            $query->whereIn('event_type', [
                                'task.planning_defect_escalated',
                                'review.retry_exhausted',
                                'task.coder_retry_exhausted',
                                'task.no_progress_detected',
                                'task.contract_drift_detected',
                                'task.review_no_progress_blocked',
                            ])->orWhere('event_type', 'like', 'task.blocked_%');
                        })
                        ->orderByDesc('occurred_at')
                        ->orderByDesc('id')
                        ->first();
                    if ($latestBlockDecision?->event_type !== 'task.planning_defect_escalated') {
                        return;
                    }

                    $eventPayload = $latestBlockDecision->getAttribute('payload');
                    $planningEscalationId = is_array($eventPayload)
                        ? $eventPayload['planning_escalation_id'] ?? null
                        : null;
                    $escalation = is_int($planningEscalationId)
                        ? $lockedTask->planningEscalations()->whereKey($planningEscalationId)->where('status', 'blocked')->lockForUpdate()->first()
                        : null;
                    if ($escalation === null || $this->planningPreflight->evaluate($lockedTask) !== null) {
                        return;
                    }

                    $resolvedEscalationIds = $lockedTask->planningEscalations()
                        ->where('status', 'blocked')
                        ->lockForUpdate()
                        ->pluck('id');
                    TaskPlanningEscalation::query()
                        ->whereIn('id', $resolvedEscalationIds)
                        ->update(['status' => 'resolved', 'resolved_at' => now()]);
                    $this->workflow->transition($lockedTask, TaskStatus::ChangesRequired);
                    $this->audit->record('task.planning_escalation_auto_resolved', [
                        'reason' => 'planning_preflight_now_valid',
                        'planning_escalation_ids' => $resolvedEscalationIds->all(),
                    ], $project, $lockedTask);
                }, attempts: 3);
            });
    }

    /**
     * A task blocked purely because CoderRepositoryGuard found the repository dirty is blocked on
     * a symptom, not a root cause: once the working tree is clean again (whether a human resolved
     * it out of band, or an earlier task's own recovery committed it), the original precondition
     * is satisfied and the task can resume without any operator action or Recovery Engineer
     * diagnosis. Other block reasons (misconfigured Agent, unsafe path, exhausted retries) still
     * require explicit human/Recovery Engineer resolution and are left untouched here.
     */
    private function autoUnblockCleanRepositories(Project $project): void
    {
        Task::query()
            ->whereBelongsTo($project)
            ->notCleared()
            ->where('status', TaskStatus::Blocked)
            ->get()
            ->each(function (Task $task) use ($project): void {
                DB::transaction(function () use ($project, $task): void {
                    $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
                    if (TaskStatus::from($lockedTask->getRawOriginal('status')) !== TaskStatus::Blocked) {
                        return;
                    }

                    if ($this->isNoProgressBlock($lockedTask)) {
                        return;
                    }

                    if ($lockedTask->planningEscalations()->whereIn('status', ['pending', 'running'])->exists()) {
                        return;
                    }

                    $lastBlockReason = $lockedTask->auditEvents()
                        ->where(function ($query): void {
                            $query->whereIn('event_type', [
                                ...array_keys(self::AutoUnblockableBlockReasons),
                                'task.planning_defect_escalated',
                                'review.retry_exhausted',
                                'task.coder_retry_exhausted',
                                'task.no_progress_detected',
                                'task.contract_drift_detected',
                                'task.review_no_progress_blocked',
                            ])->orWhere('event_type', 'like', 'task.blocked_%');
                        })
                        ->orderByDesc('occurred_at')
                        ->orderByDesc('id')
                        ->first();
                    if ($lastBlockReason === null || ! array_key_exists($lastBlockReason->event_type, self::AutoUnblockableBlockReasons)) {
                        return;
                    }

                    $lockedTask->loadMissing('project');
                    $state = $this->git->inspect($lockedTask->project->path);
                    if (! $state['clean'] || $state['base_sha'] === null) {
                        return;
                    }

                    $this->workflow->transition($lockedTask, self::AutoUnblockableBlockReasons[$lastBlockReason->event_type]);
                    $this->audit->record('task.auto_unblocked', ['reason' => 'repository_clean', 'head_sha' => $state['head_sha'], 'previous_block_event' => $lastBlockReason->event_type], $project, $lockedTask);
                }, attempts: 3);
            });
    }

    private function recoverStaleExecutions(Project $project): void
    {
        $staleAfterSeconds = (int) config('aios.stale_worker_after_seconds');
        $recovered = $this->staleWorkerRecovery->recover($project, $staleAfterSeconds);

        if ($recovered === 0) {
            return;
        }

        $incident = RecoveryIncident::create([
            'project_id' => $project->id,
            'failure_type' => 'expired_worker_lease',
            'status' => RecoveryIncidentStatus::Recovered,
            'detected_at' => now(),
            'evidence' => ['recovered_count' => $recovered],
            'root_cause' => 'A worker lease expired without heartbeat evidence of progress.',
            'root_cause_category' => 'stale_lease',
            'recoverable' => true,
            'fix_summary' => 'Interrupted the stale execution and released the expired lease so the task/roadmap attempt can resume.',
            'resulting_task_transition' => 'auto_recovered',
            'resolved_at' => now(),
        ]);
        $this->audit->record('recovery.auto_recovered', ['recovery_incident_id' => $incident->id, 'recovered_count' => $recovered], $project);
    }

    private function detectStuckTasks(Project $project): void
    {
        $staleFloor = max(1, (int) config('aios.recovery_stale_status_after_seconds'));

        Task::query()
            ->whereBelongsTo($project)
            ->notCleared()
            ->whereIn('status', self::TerminalStuckStatuses)
            ->where('updated_at', '<=', now()->subSeconds($staleFloor))
            ->get()
            ->each(function (Task $task) use ($project): void {
                $this->createIncidentIfNeeded($project, $task);
            });
    }

    private function createIncidentIfNeeded(Project $project, Task $task): void
    {
        DB::transaction(function () use ($project, $task): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $status = TaskStatus::from($lockedTask->getRawOriginal('status'));

            if (! in_array($status, self::TerminalStuckStatuses, true)) {
                return;
            }

            if ($status === TaskStatus::Blocked && $this->isNoProgressBlock($lockedTask)) {
                return;
            }

            if ($this->hasUnresolvedEscalation($lockedTask)) {
                return;
            }

            $hasOpenIncident = RecoveryIncident::query()
                ->where('task_id', $lockedTask->id)
                ->whereIn('status', array_map(fn (RecoveryIncidentStatus $status): string => $status->value, RecoveryIncidentStatus::open()))
                ->exists();

            if ($hasOpenIncident) {
                return;
            }

            $incident = RecoveryIncident::create([
                'project_id' => $project->id,
                'task_id' => $lockedTask->id,
                'failure_type' => "task_{$status->value}",
                'status' => RecoveryIncidentStatus::Detected,
                'detected_at' => now(),
                'evidence' => $this->evidence($lockedTask, $status),
            ]);
            $this->audit->record('recovery.incident_detected', [
                'recovery_incident_id' => $incident->id,
                'failure_type' => $incident->failure_type,
                'task_status' => $status->value,
            ], $project, $lockedTask);
        }, attempts: 3);
    }

    private function isNoProgressBlock(Task $task): bool
    {
        $lastNoProgressId = $task->auditEvents()
            ->where('event_type', 'task.no_progress_detected')
            ->max('id');

        if ($lastNoProgressId === null) {
            return false;
        }

        $lastRequeueId = $task->auditEvents()
            ->where('event_type', 'task.requeued')
            ->max('id');

        return $lastRequeueId === null || $lastNoProgressId > $lastRequeueId;
    }

    /**
     * A prior escalation already surfaced this task's condition for operator judgment; without
     * this guard, the still-blocked task would collect a fresh, identical RecoveryIncident (and
     * a fresh Recovery Engineer diagnosis run) on every subsequent scan until an operator
     * requeues it, since escalated incidents are terminal and therefore excluded from the
     * open-incident check above.
     */
    private function hasUnresolvedEscalation(Task $task): bool
    {
        $lastEscalatedId = $task->auditEvents()
            ->where('event_type', 'recovery.escalated')
            ->max('id');

        if ($lastEscalatedId === null) {
            return false;
        }

        $lastRequeueId = $task->auditEvents()
            ->where('event_type', 'task.requeued')
            ->max('id');

        return $lastRequeueId === null || $lastEscalatedId > $lastRequeueId;
    }

    /** @return array<string, mixed> */
    private function evidence(Task $task, TaskStatus $status): array
    {
        $attempt = $task->attempts()->latest('number')->first();
        $run = $task->runs()->latest('started_at')->first();
        $recentAuditEvents = [];
        foreach ($task->auditEvents()->latest('occurred_at')->limit(10)->get() as $event) {
            $recentAuditEvents[] = ['event_type' => $event->event_type, 'payload' => $event->payload, 'occurred_at' => $event->getRawOriginal('occurred_at')];
        }

        return [
            'task_status' => $status->value,
            'task_status_since' => $task->updated_at?->toIso8601String(),
            'latest_attempt' => $attempt?->only(['number', 'status', 'base_sha', 'head_sha', 'commit_sha', 'validation_results', 'changed_files']),
            'latest_run' => $run?->only(['id', 'status', 'exit_code', 'worker_instance_id', 'worker_lease_id']),
            'recent_audit_events' => $recentAuditEvents,
        ];
    }
}
