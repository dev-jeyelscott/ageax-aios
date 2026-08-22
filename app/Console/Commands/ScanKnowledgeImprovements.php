<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\KnowledgeArchitectAdvisor;
use App\Services\KnowledgeImprovementScanner;
use App\Services\KnowledgeSourceManifestSynchronizer;
use Illuminate\Console\Command;

class ScanKnowledgeImprovements extends Command
{
    protected $signature = 'aios:knowledge-improvements:scan {--project= : Scan only one project ID}';

    protected $description = 'Scan durable AIOS knowledge source and recurring failure evidence';

    /**
     * Complete deterministic knowledge detection first, then run bounded semantic advisory analysis.
     */
    public function handle(
        KnowledgeSourceManifestSynchronizer $sources,
        KnowledgeImprovementScanner $scanner,
        KnowledgeArchitectAdvisor $knowledgeArchitect,
    ): int {
        $projectId = $this->option('project');

        $query = Project::query()
            ->orderBy('id');

        if (is_string($projectId) && $projectId !== '') {
            if (! ctype_digit($projectId)) {
                $this->error(
                    'The --project option must be a positive integer project ID.',
                );

                return self::FAILURE;
            }

            $query->whereKey((int) $projectId);
        }

        if (! $query->exists()) {
            $this->error('No matching project was found.');

            return self::FAILURE;
        }

        $deterministicQuery = clone $query;
        $advisoryQuery = clone $query;
        $changed = 0;
        $advised = 0;

        $deterministicQuery->chunkById(
            50,
            function ($projects) use (
                $sources,
                $scanner,
                &$changed,
            ): void {
                foreach ($projects as $project) {
                    $sources->sync($project);

                    $changed += $scanner->scan(
                        $project,
                    );
                }
            },
        );

        $advisoryQuery->chunkById(
            50,
            function ($projects) use (
                $knowledgeArchitect,
                &$advised,
            ): void {
                foreach ($projects as $project) {
                    $advised +=
                        $knowledgeArchitect->analyze(
                            $project,
                        );
                }
            },
        );

        $this->info(
            "Knowledge improvement scan complete. {$changed} candidate records were created, updated, or reopened. {$advised} Knowledge Architect advisories were persisted.",
        );

        return self::SUCCESS;
    }
}
