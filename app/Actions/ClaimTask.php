<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskWorkflow;

class ClaimTask
{
    public function __construct(private TaskWorkflow $workflow) {}

    public function handle(Project $project, AgentRole $role): ?Task
    {
        return $this->workflow->claim($project, $role);
    }
}
