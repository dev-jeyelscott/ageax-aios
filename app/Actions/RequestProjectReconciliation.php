<?php

namespace App\Actions;

use App\Jobs\ProcessProjectReconciliation;
use App\Models\Project;
use App\Models\ProjectReconciliationRun;
use App\Models\User;
use App\ProjectReconciliationStatus;
use App\ProjectReconciliationTrigger;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RequestProjectReconciliation
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Request a reconciliation run for a project. Both the daily scheduler and the manual
     * "Review Project" button call this exact entry point. A project with an already-active
     * (queued or running) reconciliation run coalesces onto that run instead of creating a
     * duplicate.
     */
    public function handle(Project $project, ProjectReconciliationTrigger $trigger, ?User $requestedBy = null): ProjectReconciliationRun
    {
        return DB::transaction(function () use ($project, $trigger, $requestedBy): ProjectReconciliationRun {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);

            $active = ProjectReconciliationRun::query()
                ->whereBelongsTo($lockedProject, 'project')
                ->whereIn('status', [ProjectReconciliationStatus::Queued->value, ProjectReconciliationStatus::Running->value])
                ->latest('id')
                ->first();

            if ($active !== null) {
                return $active;
            }

            $run = ProjectReconciliationRun::create([
                'project_id' => $lockedProject->id,
                'requested_by_user_id' => $requestedBy?->id,
                'trigger' => $trigger,
                'status' => ProjectReconciliationStatus::Queued,
            ]);

            $this->audit->record('reconciliation.requested', [
                'reconciliation_run_id' => $run->id,
                'trigger' => $trigger->value,
                'requested_by_user_id' => $requestedBy?->id,
            ], $lockedProject);

            ProcessProjectReconciliation::dispatch($run->id);

            return $run;
        }, attempts: 3);
    }
}
