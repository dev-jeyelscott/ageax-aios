<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\ProjectStatus;
use App\Services\WorkflowRecoveryEngine;
use App\Services\WorkflowRecoveryScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:recover-workflows')]
#[Description('Scan every running/stopping project for actionable AIOS workflow failures and drive the Workflow Recovery Engineer (scheduled every five minutes)')]
class RecoverWorkflows extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WorkflowRecoveryScanner $scanner, WorkflowRecoveryEngine $engine): int
    {
        foreach (Project::query()->whereIn('status', [ProjectStatus::Running, ProjectStatus::Stopping])->get() as $project) {
            $engine->reclaimStaleClaims($project);
            $scanner->scan($project);
            $engine->processOpenIncidents($project);
        }

        return self::SUCCESS;
    }
}
