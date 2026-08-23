<?php

namespace App\Console\Commands;

use App\Actions\RunWorkflowRecoveryScan;
use App\Models\Project;
use App\ProjectStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:recover-workflows')]
#[Description('Scan runtime and workflow failures and drive the AIOS-owned recovery lifecycle (scheduled every five minutes)')]
class RecoverWorkflows extends Command
{
    /**
     * Execute one global runtime pass followed by one recovery pass for every active project.
     */
    public function handle(RunWorkflowRecoveryScan $scan): int
    {
        $scan->handleUnscopedRuntimeIncidents();

        foreach (Project::query()->whereIn('status', [ProjectStatus::Running, ProjectStatus::Stopping])->get() as $project) {
            $scan->handle($project);
        }

        return self::SUCCESS;
    }
}
