<?php

namespace App\Services;

final readonly class AssembledAgentContext
{
    /**
     * @param  array<string, mixed>  $agentSnapshot
     * @param  list<array<string, mixed>>  $skillsSnapshot
     * @param  array<string, mixed>  $taskContext
     * @param  array<string, mixed>  $contextCostEstimate
     * @param  array<string, mixed>  $executionSettings
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
        public array $executionSettings = [],
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
            'execution_settings' => $this->executionSettings,
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
            'execution_settings' => $this->executionSettings,
        ];
    }

    /** @param array<string, mixed> $executionSettings */
    public function withExecutionSettings(array $executionSettings): self
    {
        $payload = [
            'context_schema_version' => $this->contextSchemaVersion,
            'system_rules' => $this->systemRules,
            'agent' => $this->agentSnapshot,
            'skills' => $this->skillsSnapshot,
            'task_context' => $this->taskContext,
            'execution_settings' => $executionSettings,
        ];

        return new self(
            contextSchemaVersion: $this->contextSchemaVersion,
            systemRules: $this->systemRules,
            agentSnapshot: $this->agentSnapshot,
            skillsSnapshot: $this->skillsSnapshot,
            taskContext: $this->taskContext,
            hash: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            contextCostEstimate: $this->contextCostEstimate,
            contextCostSchemaVersion: $this->contextCostSchemaVersion,
            executionSettings: $executionSettings,
        );
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
