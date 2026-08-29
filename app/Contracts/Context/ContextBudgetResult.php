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
     * @param  array<string, mixed>  $sourceContributions
     * @param  array<string, mixed>  $reductions
     * @param  list<string>  $includedSources
     * @param  list<string>  $reducedSources
     * @param  list<string>  $excludedSources
     */
    public function __construct(
        public int $schemaVersion,
        public int $policyVersion,
        public ?string $capacitySource,
        public ?int $capacitySourceVersion,
        public int $capacityTokens,
        public int $targetTokens,
        public int $warningTokens,
        public int $hardCeilingTokens,
        public int $requiredEstimatedTokens,
        public int $originalEstimatedTokens,
        public int $finalEstimatedTokens,
        public float $utilizationBefore,
        public float $utilizationAfter,
        public string $decision,
        public array $sourceContributions,
        public array $includedSources,
        public array $reducedSources,
        public array $excludedSources,
        public array $reductions,
        public string $reductionMethod,
        public ?string $reductionReason,
        public ?string $warningReason,
        public ?string $blockReason,
        public bool $blocked,
        public string $originalContextHash,
        public string $finalContextHash,
    ) {}

    public static function fromDecision(ContextBudgetDecision $decision): self
    {
        $evidence = $decision->evidence;

        foreach ([
            'schema_version', 'policy_version', 'resolved_capacity_tokens', 'budget_tokens',
            'warning_tokens', 'hard_ceiling_tokens', 'required_estimated_tokens',
            'original_estimated_tokens', 'final_estimated_tokens', 'utilization_before',
            'utilization_after', 'decision', 'source_contributions', 'included_sources',
            'reduced_sources', 'excluded_sources', 'reductions', 'reduction_method',
            'original_context_hash', 'final_context_hash',
        ] as $key) {
            if (! array_key_exists($key, $evidence)) {
                throw new LogicException("Context Budget evidence is missing required key [{$key}].");
            }
        }

        return new self(
            schemaVersion: $evidence['schema_version'],
            policyVersion: $evidence['policy_version'],
            capacitySource: $evidence['capacity_source'] ?? null,
            capacitySourceVersion: $evidence['capacity_source_version'] ?? null,
            capacityTokens: $evidence['resolved_capacity_tokens'],
            targetTokens: $evidence['budget_tokens'],
            warningTokens: $evidence['warning_tokens'],
            hardCeilingTokens: $evidence['hard_ceiling_tokens'],
            requiredEstimatedTokens: $evidence['required_estimated_tokens'],
            originalEstimatedTokens: $evidence['original_estimated_tokens'],
            finalEstimatedTokens: $evidence['final_estimated_tokens'],
            utilizationBefore: $evidence['utilization_before'],
            utilizationAfter: $evidence['utilization_after'],
            decision: $evidence['decision'],
            sourceContributions: $evidence['source_contributions'],
            includedSources: $evidence['included_sources'],
            reducedSources: $evidence['reduced_sources'],
            excludedSources: $evidence['excluded_sources'],
            reductions: $evidence['reductions'],
            reductionMethod: $evidence['reduction_method'],
            reductionReason: $evidence['reduction_reason'] ?? null,
            warningReason: $evidence['warning_reason'] ?? null,
            blockReason: $evidence['block_reason'] ?? null,
            blocked: $decision->blocked,
            originalContextHash: $evidence['original_context_hash'],
            finalContextHash: $evidence['final_context_hash'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'policy_version' => $this->policyVersion,
            'capacity_source' => $this->capacitySource,
            'capacity_source_version' => $this->capacitySourceVersion,
            'capacity_tokens' => $this->capacityTokens,
            'target_tokens' => $this->targetTokens,
            'warning_tokens' => $this->warningTokens,
            'hard_ceiling_tokens' => $this->hardCeilingTokens,
            'required_estimated_tokens' => $this->requiredEstimatedTokens,
            'original_estimated_tokens' => $this->originalEstimatedTokens,
            'final_estimated_tokens' => $this->finalEstimatedTokens,
            'utilization_before' => $this->utilizationBefore,
            'utilization_after' => $this->utilizationAfter,
            'decision' => $this->decision,
            'source_contributions' => $this->sourceContributions,
            'included_sources' => $this->includedSources,
            'reduced_sources' => $this->reducedSources,
            'excluded_sources' => $this->excludedSources,
            'reductions' => $this->reductions,
            'reduction_method' => $this->reductionMethod,
            'reduction_reason' => $this->reductionReason,
            'warning_reason' => $this->warningReason,
            'block_reason' => $this->blockReason,
            'blocked' => $this->blocked,
            'original_context_hash' => $this->originalContextHash,
            'final_context_hash' => $this->finalContextHash,
        ];
    }
}
