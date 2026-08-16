<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
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
 *    Workflow Recovery Engineer to diagnose and, where safe, repair.
 */
class WorkflowRecoveryScanner
{
    private const array TerminalStuckStatuses = [TaskStatus::Blocked, TaskStatus::Interrupted, TaskStatus::Failed];

    public function __construct(private StaleWorkerRecovery $staleWorkerRecovery, private AuditLogger $audit) {}

    public function scan(Project $project): void
    {
        $this->recoverStaleExecutions($project);
        $this->detectStuckTasks($project);
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
