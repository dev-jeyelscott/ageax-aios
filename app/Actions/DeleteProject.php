<?php

namespace App\Actions;

use App\Models\Project;
use App\Services\AuditLogger;

class DeleteProject
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Project $project): void
    {
        $this->audit->record('project.deleted', ['name' => $project->name], $project);

        $project->delete();
    }
}
