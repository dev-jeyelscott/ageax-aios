<?php

namespace App\Contracts\Context;

use App\Services\ContextBudgetDecision;
use LogicException;

/**
 * Provider-independent, auditable Context Budget outcome. Wraps the exact evidence already
 * produced by ContextBudgetGuard/ContextBudgetPolicy; it never recomputes or competes with
 * that deterministic boundary.
 */
final readonly class ContextBudgetResult
{
    /**
     * @param  list<string>  $includedSources
     * @param  list<string>  $reducedSources
     * @param  list<string>  $excludedSources
     */
    public function __construct(
        public int $schemaVersion,
        public int $policyVersion,
        public int $capacityTokens,
        public int $targetTokens,
        public int $warningTokens,
        public int $hardCeilingTokens,
        public int $originalEstimatedTokens,
        public int $finalEstimatedTokens,
        public float $utilizationBefore,
        public float $utilizationAfter,
        public string $decision,
        public array $includedSources,
        public array $reducedSources,
        public array $excludedSources,
        public ?string $warningReason,
        public ?string $blockReason,
        public bool $blocked,
    ) {}

    public static function fromDecision(ContextBudgetDecision $decision): self
    {
        $evidence = $decision->evidence;

        foreach ([
            'schema_version', 'policy_version', 'resolved_capacity_tokens', 'budget_tokens',
            'warning_tokens', 'hard_ceiling_tokens', 'original_estimated_tokens',
            'final_estimated_tokens', 'utilization_before', 'utilization_after', 'decision',
            'included_sources', 'reduced_sources', 'excluded_sources',
        ] as $key) {
            if (! array_key_exists($key, $evidence)) {
                throw new LogicException("Context Budget evidence is missing required key [{$key}].");
            }
        }

        return new self(
            schemaVersion: $evidence['schema_version'],
            policyVersion: $evidence['policy_version'],
            capacityTokens: $evidence['resolved_capacity_tokens'],
            targetTokens: $evidence['budget_tokens'],
            warningTokens: $evidence['warning_tokens'],
            hardCeilingTokens: $evidence['hard_ceiling_tokens'],
            originalEstimatedTokens: $evidence['original_estimated_tokens'],
            finalEstimatedTokens: $evidence['final_estimated_tokens'],
            utilizationBefore: $evidence['utilization_before'],
            utilizationAfter: $evidence['utilization_after'],
            decision: $evidence['decision'],
            includedSources: $evidence['included_sources'],
            reducedSources: $evidence['reduced_sources'],
            excludedSources: $evidence['excluded_sources'],
            warningReason: $evidence['warning_reason'] ?? null,
            blockReason: $evidence['block_reason'] ?? null,
            blocked: $decision->blocked,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'policy_version' => $this->policyVersion,
            'capacity_tokens' => $this->capacityTokens,
            'target_tokens' => $this->targetTokens,
            'warning_tokens' => $this->warningTokens,
            'hard_ceiling_tokens' => $this->hardCeilingTokens,
            'original_estimated_tokens' => $this->originalEstimatedTokens,
            'final_estimated_tokens' => $this->finalEstimatedTokens,
            'utilization_before' => $this->utilizationBefore,
            'utilization_after' => $this->utilizationAfter,
            'decision' => $this->decision,
            'included_sources' => $this->includedSources,
            'reduced_sources' => $this->reducedSources,
            'excluded_sources' => $this->excludedSources,
            'warning_reason' => $this->warningReason,
            'block_reason' => $this->blockReason,
            'blocked' => $this->blocked,
        ];
    }
}
