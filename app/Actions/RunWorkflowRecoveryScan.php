<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\RecoveryIncident;
use App\RecoveryIncidentStatus;
use App\Services\WorkflowBoundaryHandoffRecorder;
use App\Services\WorkflowRecoveryEngine;
use App\Services\WorkflowRecoveryScanner;

/**
 * One Workflow Recovery Engineer pass for a single project: reclaim abandoned incident claims,
 * detect new actionable failures, then process every open incident. Shared by the durable
 * five-minute scheduled scan (App\Console\Commands\RecoverWorkflows) and an on-demand manual
 * invocation, so both run identical, already-tested recovery logic.
 */
class RunWorkflowRecoveryScan
{
    /**
     * Inject the existing recovery scanner/engine and post-boundary handoff recorder.
     */
    public function __construct(
        private WorkflowRecoveryScanner $scanner,
        private WorkflowRecoveryEngine $engine,
        private WorkflowBoundaryHandoffRecorder $boundaryHandoffs,
    ) {}

    /**
     * Reconcile one running or stopping project's workflow/runtime incidents and accepted recovery handoffs.
     */
    public function handle(Project $project): void
    {
        $this->engine->reclaimStaleClaims($project);
        $this->scanner->scan($project);

        $processedIncidentIds = RecoveryIncident::query()
            ->whereBelongsTo($project)
            ->where('status', RecoveryIncidentStatus::Detected->value)
            ->orderBy('detected_at')
            ->pluck('id')
            ->all();

        $this->engine->processOpenIncidents($project);

        if ($processedIncidentIds === []) {
            return;
        }

        $processedIncidents = RecoveryIncident::query()
            ->whereBelongsTo($project)
            ->whereKey($processedIncidentIds)
            ->orderBy('id')
            ->get();

        foreach ($processedIncidents as $incident) {
            $this->boundaryHandoffs->recordRecoveryAdvice($incident);
        }
    }

    /**
     * Reconcile runtime incidents that cannot be safely attributed to a managed project.
     */
    public function handleUnscopedRuntimeIncidents(): void
    {
        $this->engine->reclaimStaleUnscopedRuntimeClaims();
        $this->engine->processUnscopedRuntimeIncidents();
    }
}
