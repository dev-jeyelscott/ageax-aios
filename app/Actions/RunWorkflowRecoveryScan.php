<?php

namespace App\Actions;

use App\Models\Project;
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
    public function __construct(private WorkflowRecoveryScanner $scanner, private WorkflowRecoveryEngine $engine) {}

    /**
     * Reconcile one running or stopping project's workflow and runtime recovery incidents.
     */
    public function handle(Project $project): void
    {
        $this->engine->reclaimStaleClaims($project);
        $this->scanner->scan($project);
        $this->engine->processOpenIncidents($project);
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
