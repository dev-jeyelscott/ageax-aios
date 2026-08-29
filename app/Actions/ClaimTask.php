<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskWorkflow;
use App\WorkerLease;

class ClaimTask
{
    /**
     * Create the Action with the authoritative Task workflow service.
     */
    public function __construct(private TaskWorkflow $workflow) {}

    /**
     * Use serial claiming by default and the lease-bound concurrent Coder primitive only when explicitly requested.
     */
    public function handle(
        Project $project,
        AgentRole $role,
        ?WorkerLease $lease = null,
    ): ?Task {
        if ($role === AgentRole::Coder && $lease !== null) {
            return $this->workflow->claimConcurrentCoder(
                $project,
                $lease,
            );
        }

        return $this->workflow->claim($project, $role);
    }
}
