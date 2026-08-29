<?php

namespace App\Contracts\Context;

use App\Services\AssembledAgentContext;

/**
 * Provider-independent, auditable representation of an already-assembled Agent execution
 * context. Never persists raw context content: only the deterministic per-source token
 * measurement already computed by ContextCostEstimator, plus the existing provenance hash.
 */
final readonly class ContextPack
{
    /** @param list<ContextSource> $sources */
    public function __construct(
        public int $projectId,
        public int $agentId,
        public int $contextSchemaVersion,
        public string $hash,
        public array $sources,
        public int $totalEstimatedTokens,
    ) {}

    public static function fromAssembledContext(ContextRequest $request, AssembledAgentContext $context): self
    {
        $estimate = $context->contextCostEstimate;

        $sources = [
            new ContextSource(
                'system_rules',
                'workflow',
                'aios_owned_system_rules_non_overridable',
                $estimate['system_rules']['estimated_tokens'],
                ContextAuthority::Required,
            ),
            new ContextSource(
                'task_core',
                'task',
                'current_task_objective_and_acceptance_criteria',
                $estimate['task_core']['estimated_tokens'],
                ContextAuthority::Required,
            ),
            new ContextSource(
                'retry_recovery_evidence',
                'task',
                'previous_attempt_recovery_evidence',
                $estimate['retry_recovery_evidence']['estimated_tokens'],
                ContextAuthority::Required,
            ),
            new ContextSource(
                'review_evidence',
                'task',
                'reviewer_findings_evidence',
                $estimate['review_evidence']['estimated_tokens'],
                ContextAuthority::Required,
            ),
            new ContextSource(
                'agent_default_context',
                'agent',
                'agent_default_context_supplementary_guidance',
                $estimate['agent_default_context']['estimated_tokens'],
                ContextAuthority::Reducible,
            ),
            new ContextSource(
                'skills',
                'agent',
                'assigned_skill_instructions_and_constraints',
                $estimate['skills_total']['estimated_tokens'],
                ContextAuthority::Reducible,
            ),
            new ContextSource(
                'obsidian_context',
                'project',
                'targeted_project_knowledge_retrieval',
                $estimate['obsidian_context']['estimated_tokens'],
                ContextAuthority::Reducible,
            ),
        ];

        return new self(
            projectId: $request->projectId,
            agentId: $request->agentId,
            contextSchemaVersion: $context->contextSchemaVersion,
            hash: $context->hash,
            sources: $sources,
            totalEstimatedTokens: $estimate['total']['estimated_tokens'],
        );
    }

    /** @return list<ContextSource> */
    public function requiredSources(): array
    {
        return array_values(array_filter(
            $this->sources,
            fn (ContextSource $source): bool => $source->isRequired(),
        ));
    }

    /** @return list<ContextSource> */
    public function reducibleSources(): array
    {
        return array_values(array_filter(
            $this->sources,
            fn (ContextSource $source): bool => ! $source->isRequired(),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'agent_id' => $this->agentId,
            'context_schema_version' => $this->contextSchemaVersion,
            'context_hash' => $this->hash,
            'total_estimated_tokens' => $this->totalEstimatedTokens,
            'sources' => array_map(
                fn (ContextSource $source): array => $source->toArray(),
                $this->sources,
            ),
        ];
    }
}
