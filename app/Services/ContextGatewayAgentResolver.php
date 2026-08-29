<?php

namespace App\Services;

use App\AgentRole;
use App\Contracts\Context\AgentIdentity;
use App\Exceptions\AgentResolutionFailed;
use App\Models\Agent;

/**
 * Resolves the approved durable logical Agent identity a standalone Context Gateway caller may
 * use to scope memory and context retrieval, inside exactly one already-resolved AIOS Project
 * scope. Reuses the existing Agent ownership, enabled-state, and role invariants; never consults
 * AgentWorker leases, harness/model configuration, process IDs, or session IDs, so the resolved
 * identity remains distinct from AgentWorker runtime state and grants no workflow authority.
 */
class ContextGatewayAgentResolver
{
    /**
     * Project-scoped workflow roles a standalone Context Gateway caller may resolve. Matches
     * the existing Agent::ProjectRoles invariant so an Agent record cannot exist outside this
     * set for a project-scoped Agent, but is enforced independently here so retrieval fails
     * closed even if that invariant is ever bypassed.
     */
    private const array SupportedRoles = [
        AgentRole::ProjectManager,
        AgentRole::Coder,
        AgentRole::Reviewer,
    ];

    public function resolve(int $projectId, int $agentId): AgentIdentity
    {
        $agent = Agent::query()->find($agentId);

        if ($agent === null) {
            throw new AgentResolutionFailed("No registered Agent matches the Agent ID [{$agentId}].");
        }

        if ($agent->project_id === null) {
            throw new AgentResolutionFailed('A global system Agent cannot be resolved as a Project-scoped logical Agent identity.');
        }

        if ($agent->project_id !== $projectId) {
            throw new AgentResolutionFailed('This Agent identity does not belong to the resolved Project.');
        }

        if (! $agent->enabled) {
            throw new AgentResolutionFailed('This Agent identity is disabled and cannot be resolved for retrieval.');
        }

        if (! in_array($agent->role, self::SupportedRoles, true)) {
            throw new AgentResolutionFailed('This Agent role is not supported for Context Gateway retrieval.');
        }

        return new AgentIdentity($agent->id, $agent->project_id, $agent->role->value, $agent->configuration_version);
    }
}
