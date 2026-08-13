<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Roadmap;
use App\Services\RoadmapIntake;
use Illuminate\Http\UploadedFile;

class StoreRoadmap
{
    public function __construct(private RoadmapIntake $intake) {}

    public function handle(Project $project, UploadedFile $file): Roadmap
    {
        return $this->intake->captureUpload($project, $file) ?? $project->roadmaps()->latest('id')->firstOrFail();
    }
}
