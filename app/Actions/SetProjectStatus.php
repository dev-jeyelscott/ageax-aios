<?php

namespace App\Actions;

use App\Models\Project;
use App\ProjectStatus;
use App\Services\AuditLogger;

class SetProjectStatus
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Project $project, ProjectStatus $status): Project
    {
        $currentStatus = ProjectStatus::from($project->getRawOriginal('status'));
        $nextStatus = $status === ProjectStatus::Paused && $currentStatus === ProjectStatus::Running
            ? ProjectStatus::Stopping
            : $status;
        $project->update(['status' => $nextStatus, 'paused_at' => $nextStatus === ProjectStatus::Paused ? now() : null]);
        $this->audit->record('project.status_changed', ['status' => $nextStatus->value], $project);

        return $project->refresh();
    }

    public function completeStopping(Project $project): Project
    {
        if (ProjectStatus::from($project->getRawOriginal('status')) !== ProjectStatus::Stopping) {
            return $project->refresh();
        }

        $project->update(['status' => ProjectStatus::Paused, 'paused_at' => now()]);
        $this->audit->record('project.status_changed', ['status' => ProjectStatus::Paused->value], $project);

        return $project->refresh();
    }
}
