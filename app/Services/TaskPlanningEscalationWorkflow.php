<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\TaskPlanningEscalation;
use App\Models\TaskPlanningRevisionAttempt;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;

class TaskPlanningEscalationWorkflow
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{type:string,fingerprint:string,evidence:array<string,mixed>,allowed_fields:list<string>} $defect */
    public function escalate(Task $task, array $defect, ?TaskAttempt $sourceAttempt = null): TaskPlanningEscalation
    {
        return DB::transaction(function () use ($task, $defect, $sourceAttempt): TaskPlanningEscalation {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $existing = $lockedTask->planningEscalations()->whereIn('status', ['pending', 'running'])->first();
            if ($existing !== null) {
                if (TaskStatus::from($lockedTask->getRawOriginal('status')) !== TaskStatus::Blocked) {
                    $from = $lockedTask->getRawOriginal('status');
                    $lockedTask->update(['status' => TaskStatus::Blocked]);
                    $this->audit->record('task.transitioned', ['from' => $from, 'to' => TaskStatus::Blocked->value], $lockedTask->project, $lockedTask);
                    $this->audit->record('task.planning_escalation_state_repaired', ['planning_escalation_id' => $existing->id, 'reason' => 'active_revision_requires_blocked_task'], $lockedTask->project, $lockedTask);
                }

                return $existing;
            }

            $attempt = $sourceAttempt === null
                ? TaskAttempt::create([
                    'task_id' => $lockedTask->id,
                    'number' => ((int) $lockedTask->attempts()->max('number')) + 1,
                    'status' => 'failed',
                    'validation_results' => ['passed' => false, 'checks' => ['planning_defect_preflight' => false], 'planning_defect' => ['type' => $defect['type'], 'fingerprint' => $defect['fingerprint'], 'evidence' => $defect['evidence']]],
                    'started_at' => now(), 'finished_at' => now(),
                ])
                : TaskAttempt::query()->lockForUpdate()->findOrFail($sourceAttempt->id);
            if ($sourceAttempt !== null) {
                $validation = $attempt->validation_results ?? [];
                $attempt->update(['status' => 'failed', 'validation_results' => [...$validation, 'passed' => false, 'checks' => [...($validation['checks'] ?? []), 'planning_defect' => false], 'planning_defect' => ['type' => $defect['type'], 'fingerprint' => $defect['fingerprint'], 'evidence' => $defect['evidence']]], 'finished_at' => now()]);
            }
            $escalation = TaskPlanningEscalation::create([
                'task_id' => $lockedTask->id, 'source_task_attempt_id' => $attempt->id,
                'defect_type' => $defect['type'], 'fingerprint' => $defect['fingerprint'],
                'failure_evidence' => $defect['evidence'], 'allowed_fields' => $defect['allowed_fields'], 'status' => 'pending',
            ]);
            TaskPlanningRevisionAttempt::create(['task_planning_escalation_id' => $escalation->id, 'number' => 1, 'status' => 'queued', 'claimed_at' => now()]);
            $from = $lockedTask->getRawOriginal('status');
            $lockedTask->update(['status' => TaskStatus::Blocked]);
            $this->audit->record('task.planning_defect_escalated', ['attempt_number' => $attempt->number, 'planning_escalation_id' => $escalation->id, 'defect_type' => $defect['type'], 'fingerprint' => $defect['fingerprint'], 'allowed_fields' => $defect['allowed_fields']], $lockedTask->project, $lockedTask);
            $this->audit->record('task.transitioned', ['from' => $from, 'to' => TaskStatus::Blocked->value], $lockedTask->project, $lockedTask);

            return $escalation;
        }, attempts: 3);
    }

    public function claim(Project $project): ?TaskPlanningRevisionAttempt
    {
        return DB::transaction(function () use ($project): ?TaskPlanningRevisionAttempt {
            $escalation = TaskPlanningEscalation::query()->where('status', 'pending')->whereHas('task', fn ($query) => $query->where('project_id', $project->id)->where('status', TaskStatus::Blocked->value))->oldest('id')->lockForUpdate()->first();
            if ($escalation === null) {
                return null;
            }
            $attempt = $escalation->revisionAttempts()->where('status', 'queued')->oldest('number')->lockForUpdate()->first();
            if ($attempt === null) {
                return null;
            }
            $attempt->update(['status' => 'claimed']);
            $escalation->update(['status' => 'running']);

            return $attempt->refresh();
        }, attempts: 3);
    }
}
