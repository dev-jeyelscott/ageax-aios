<?php

namespace App\Contracts\Context;

/**
 * Provider-independent logical Agent identity evidence produced by resolving a registered,
 * enabled, approved Agent inside exactly one resolved AIOS Project scope. Stable across
 * supported harness, model, and process/session changes because it is derived only from the
 * durable Agent record, never from AgentWorker leases, harness/model configuration, process
 * IDs, or session IDs. Grants no Task, Ticket, AgentWorker, or workflow-transition authority;
 * it only scopes future memory and context retrieval.
 */
final readonly class AgentIdentity
{
    public function __construct(
        public int $agentId,
        public int $projectId,
        public string $role,
        public int $configurationVersion,
    ) {}

    /** @param array<string, mixed> $taskContext */
    public function toContextRequest(array $taskContext): ContextRequest
    {
        return new ContextRequest($this->projectId, $this->agentId, $taskContext);
    }

    /** @return array{agent_id: int, project_id: int, role: string, configuration_version: int} */
    public function toArray(): array
    {
        return [
            'agent_id' => $this->agentId,
            'project_id' => $this->projectId,
            'role' => $this->role,
            'configuration_version' => $this->configurationVersion,
        ];
    }
}
