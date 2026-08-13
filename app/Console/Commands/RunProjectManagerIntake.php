<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\ProjectStatus;
use App\Services\RoadmapIntake;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:project-manager {--once : Scan currently due projects once}')]
#[Description('Capture due implementation roadmaps for running projects')]
class RunProjectManagerIntake extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RoadmapIntake $intake): int
    {
        $captured = Project::query()
            ->where('status', ProjectStatus::Running)
            ->get()
            ->filter(fn (Project $project): bool => $intake->scan($project) !== null)
            ->count();

        $this->info("Captured {$captured} roadmap(s).");

        return self::SUCCESS;
    }
}
