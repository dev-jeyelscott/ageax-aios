<?php

namespace App\Contracts\Context;

/**
 * One provider-independent, auditable context item inside a ContextPack. Estimated tokens are
 * sourced from the existing deterministic ContextCostEstimator measurement, never re-derived.
 */
final readonly class ContextSource
{
    public function __construct(
        public string $key,
        public string $scope,
        public string $reason,
        public int $estimatedTokens,
        public ContextAuthority $authority,
    ) {}

    public function isRequired(): bool
    {
        return $this->authority === ContextAuthority::Required;
    }

    /** @return array{source: string, scope: string, reason: string, estimated_tokens: int, authority: string} */
    public function toArray(): array
    {
        return [
            'source' => $this->key,
            'scope' => $this->scope,
            'reason' => $this->reason,
            'estimated_tokens' => $this->estimatedTokens,
            'authority' => $this->authority->value,
        ];
    }
}
