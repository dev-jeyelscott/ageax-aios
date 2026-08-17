<?php

namespace App\Services;

final readonly class AssembledAgentContext
{
    /**
     * @param  array<string, mixed>  $agentSnapshot
     * @param  list<array<string, mixed>>  $skillsSnapshot
     * @param  array<string, mixed>  $taskContext
     * @param  array<string, mixed>  $contextCostEstimate
     */
    public function __construct(
        public int $contextSchemaVersion,
        public string $systemRules,
        public array $agentSnapshot,
        public array $skillsSnapshot,
        public array $taskContext,
        public string $hash,
        public array $contextCostEstimate,
        public int $contextCostSchemaVersion,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'context_schema_version' => $this->contextSchemaVersion,
            'context_hash' => $this->hash,
            'system_rules' => $this->systemRules,
            'agent' => $this->agentSnapshot,
            'skills' => $this->skillsSnapshot,
            'task_context' => $this->taskContext,
        ];
    }

    /** @return array<string, mixed> */
    public function configurationSnapshot(): array
    {
        return [
            'context_schema_version' => $this->contextSchemaVersion,
            'context_hash' => $this->hash,
            'agent' => $this->agentSnapshot,
            'skills' => $this->skillsSnapshot,
        ];
    }

    /** @return array<string, mixed> */
    public function costEstimateSnapshot(): array
    {
        return [
            'context_cost_schema_version' => $this->contextCostSchemaVersion,
            'context_cost_estimate' => $this->contextCostEstimate,
        ];
    }
}
