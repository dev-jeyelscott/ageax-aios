<?php

namespace App\Console\Commands;

use App\Actions\RunWorkflowRecoveryScan;
use App\Models\Project;
use App\ProjectStatus;
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
    public function handle(RunWorkflowRecoveryScan $scan): int
    {
        foreach (Project::query()->whereIn('status', [ProjectStatus::Running, ProjectStatus::Stopping])->get() as $project) {
            $scan->handle($project);
        }

        return self::SUCCESS;
    }
}
