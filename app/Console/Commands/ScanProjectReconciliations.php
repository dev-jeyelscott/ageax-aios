<?php

namespace App\Console\Commands;

use App\Actions\RequestProjectReconciliation;
use App\Models\Project;
use App\ProjectReconciliationTrigger;
use Illuminate\Console\Command;

class ScanProjectReconciliations extends Command
{
    protected $signature = 'aios:reconciliation:scan {--project= : Request reconciliation for only one project ID}';

    protected $description = 'Request a daily reconciliation audit run for every eligible project';

    public function handle(RequestProjectReconciliation $reconciliation): int
    {
        $projectId = $this->option('project');

        $query = Project::query()->orderBy('id');

        if (is_string($projectId) && $projectId !== '') {
            if (! ctype_digit($projectId)) {
                $this->error('The --project option must be a positive integer project ID.');

                return self::FAILURE;
            }

            $query->whereKey((int) $projectId);
        }

        if (! $query->exists()) {
            $this->error('No matching project was found.');

            return self::FAILURE;
        }

        $requested = 0;

        $query->chunkById(50, function ($projects) use ($reconciliation, &$requested): void {
            foreach ($projects as $project) {
                $reconciliation->handle($project, ProjectReconciliationTrigger::Scheduled);
                $requested++;
            }
        });

        $this->info("Project reconciliation scan complete. {$requested} project(s) were requested (an already-active run coalesces rather than duplicating).");

        return self::SUCCESS;
    }
}
