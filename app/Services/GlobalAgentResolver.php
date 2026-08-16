<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Agent;
use LogicException;

/**
 * Resolves the singleton global (project_id null) Agent configured for an AIOS system role, e.g.
 * the Workflow Recovery Engineer. Unlike project Agents, a global Agent is always seeded and
 * never unbound; a missing or disabled row is always a blocking misconfiguration for the caller
 * to handle explicitly, never a legacy fallback path.
 */
class GlobalAgentResolver
{
    public function forRole(AgentRole $role): Agent
    {
        $agent = Agent::query()->whereNull('project_id')->where('role', $role)->first();

        if ($agent === null || ! $agent->enabled) {
            throw new LogicException("The global Agent configured for the [{$role->value}] system role is missing or disabled.");
        }

        return $agent;
    }
}
