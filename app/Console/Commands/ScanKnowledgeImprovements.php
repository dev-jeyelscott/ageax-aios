<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\KnowledgeImprovementScanner;
use Illuminate\Console\Command;

class ScanKnowledgeImprovements extends Command
{
    protected $signature = 'aios:knowledge-improvements:scan {--project= : Scan only one project ID}';

    protected $description = 'Scan durable AIOS failure evidence for recurring operator-reviewable knowledge improvements';

    public function handle(KnowledgeImprovementScanner $scanner): int
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

        $changed = 0;

        $query->chunkById(50, function ($projects) use ($scanner, &$changed): void {
            foreach ($projects as $project) {
                $changed += $scanner->scan($project);
            }
        });

        $this->info("Knowledge improvement scan complete. {$changed} candidate records were created, updated, or reopened.");

        return self::SUCCESS;
    }
}
