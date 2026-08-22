<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Agent;
use LogicException;

/**
 * Resolve singleton global AIOS system Agents without introducing fallback behavior.
 */
class GlobalAgentResolver
{
    /**
     * Resolve one enabled global Agent for the requested approved system role.
     */
    public function forRole(AgentRole $role): Agent
    {
        $agent = Agent::query()
            ->whereNull('project_id')
            ->where('role', $role)
            ->first();

        if ($agent === null) {
            throw new LogicException(
                "The global Agent configured for the [{$role->value}] system role is missing.",
            );
        }

        if (! $agent->enabled) {
            throw new LogicException(
                "The global Agent configured for the [{$role->value}] system role is disabled.",
            );
        }

        return $agent;
    }
}
