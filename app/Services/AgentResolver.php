<?php

namespace App\Services;

use App\AgentRole;
use App\Exceptions\AgentNotBoundToRole;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use LogicException;

/**
 * Resolves the project Agent bound to a required AIOS workflow role before execution.
 *
 * Two distinct failure modes are surfaced: a role with no Agent bound at all throws
 * AgentNotBoundToRole, which callers treat as the legacy, backward-compatible execution
 * path. A role bound to an Agent that is missing or disabled throws the base
 * LogicException, which callers must treat as a blocking, actionable misconfiguration
 * rather than falling back silently.
 */
class AgentResolver
{
    public function forRole(Project $project, AgentRole $role): Agent
    {
        $worker = AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->first();

        if ($worker === null || $worker->agent_id === null) {
            throw new AgentNotBoundToRole("No Agent is bound to the [{$role->value}] workflow worker for this project.");
        }

        $agent = Agent::query()->find($worker->agent_id);

        if ($agent === null || ! $agent->enabled) {
            throw new LogicException("The Agent bound to the [{$role->value}] workflow worker is missing or disabled.");
        }

        return $agent;
    }
}
