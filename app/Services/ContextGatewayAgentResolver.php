<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;

final class ContextGatewayAgentResolver
{
    /**
     * Resolve the active project Agent for one supported retrieval role.
     */
    public function resolve(
        Project $project,
        AgentRole $role,
    ): ?ContextGatewayAgentIdentity {
        if (
            ! in_array(
                $role,
                [
                    AgentRole::ProjectManager,
                    AgentRole::Coder,
                    AgentRole::Reviewer,
                ],
                true,
            )
        ) {
            return null;
        }

        $agents = Agent::query()
            ->whereBelongsTo($project)
            ->where('role', $role)
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($agents->isEmpty()) {
            return null;
        }

        if ($agents->count() !== 1) {
            throw ContextGatewayAgentResolutionFailed::ambiguous(
                $project,
                $role,
            );
        }

        $agent = $agents->firstOrFail();
        $resolvedRole = $agent->getAttribute('role');

        if (
            ! $resolvedRole instanceof AgentRole
            || ! in_array(
                $resolvedRole,
                [
                    AgentRole::ProjectManager,
                    AgentRole::Coder,
                    AgentRole::Reviewer,
                ],
                true,
            )
        ) {
            return null;
        }

        return new ContextGatewayAgentIdentity(
            id: $agent->id,
            role: $resolvedRole->value,
            name: $agent->name,
        );
    }
}
