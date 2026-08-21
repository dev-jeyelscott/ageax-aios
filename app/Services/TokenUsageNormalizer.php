<?php

namespace App\Services;

use App\AgentHarness;
use App\Models\AgentRun;

final class TokenUsageNormalizer
{
    public const int SchemaVersion = 1;

    /**
     * Normalize a provider usage payload into AIOS's canonical processed-token evidence.
     *
     * @param  array<string, mixed>|null  $usage
     * @return array<string, int|string|null>
     */
    public function normalize(?string $harness, ?array $usage): array
    {
        $resolvedHarness = $harness ?? 'legacy';
        $inputTokens = $this->nonNegativeInteger($usage['input_tokens'] ?? null);
        $outputTokens = $this->nonNegativeInteger($usage['output_tokens'] ?? null);

        if ($inputTokens === null || $outputTokens === null) {
            return $this->incomplete($resolvedHarness);
        }

        if ($resolvedHarness === AgentHarness::ClaudeCode->value) {
            $cacheCreationTokens = $this->optionalNonNegativeInteger($usage, 'cache_creation_input_tokens');
            $cacheReadTokens = $this->optionalNonNegativeInteger($usage, 'cache_read_input_tokens');

            if ($cacheCreationTokens === null || $cacheReadTokens === null) {
                return $this->incomplete($resolvedHarness);
            }

            return [
                'schema_version' => self::SchemaVersion,
                'status' => 'complete',
                'harness' => $resolvedHarness,
                'input_tokens' => $inputTokens,
                'uncached_input_tokens' => $inputTokens,
                'cached_input_tokens' => $cacheReadTokens,
                'cache_creation_input_tokens' => $cacheCreationTokens,
                'cache_read_input_tokens' => $cacheReadTokens,
                'output_tokens' => $outputTokens,
                'canonical_total_tokens' => $inputTokens + $cacheCreationTokens + $cacheReadTokens + $outputTokens,
                'canonical_total_method' => 'anthropic_input_plus_cache_creation_plus_cache_read_plus_output',
            ];
        }

        $cachedInputTokens = $this->codexCachedInputTokens($usage);

        if ($cachedInputTokens === null || $cachedInputTokens > $inputTokens) {
            return $this->incomplete($resolvedHarness);
        }

        return [
            'schema_version' => self::SchemaVersion,
            'status' => 'complete',
            'harness' => $resolvedHarness,
            'input_tokens' => $inputTokens,
            'uncached_input_tokens' => $inputTokens - $cachedInputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'cache_creation_input_tokens' => null,
            'cache_read_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
            'canonical_total_tokens' => $inputTokens + $outputTokens,
            'canonical_total_method' => 'input_includes_cached_tokens_plus_output',
        ];
    }

    public function canonicalTotal(AgentRun $run): ?int
    {
        $result = $run->getAttribute('result');
        $evidence = is_array($result) ? $result['token_usage'] ?? null : null;

        if (is_array($evidence) && ($evidence['status'] ?? null) === 'complete') {
            return $this->nonNegativeInteger($evidence['canonical_total_tokens'] ?? null);
        }

        if ($run->getRawOriginal('harness') === AgentHarness::ClaudeCode->value) {
            return null;
        }

        return $this->nonNegativeInteger($run->getAttribute('token_usage'));
    }

    /** @param array<string, mixed> $usage */
    private function codexCachedInputTokens(array $usage): ?int
    {
        $details = $usage['input_tokens_details'] ?? null;
        $value = is_array($details)
            ? $details['cached_tokens'] ?? null
            : ($usage['cached_input_tokens'] ?? $usage['cache_read_input_tokens'] ?? null);

        return $value === null ? 0 : $this->nonNegativeInteger($value);
    }

    /** @param array<string, mixed> $usage */
    private function optionalNonNegativeInteger(array $usage, string $key): ?int
    {
        return array_key_exists($key, $usage)
            ? $this->nonNegativeInteger($usage[$key])
            : 0;
    }

    /** @return array<string, int|string|null> */
    private function incomplete(string $harness): array
    {
        return [
            'schema_version' => self::SchemaVersion,
            'status' => 'incomplete',
            'harness' => $harness,
            'input_tokens' => null,
            'uncached_input_tokens' => null,
            'cached_input_tokens' => null,
            'cache_creation_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'output_tokens' => null,
            'canonical_total_tokens' => null,
            'canonical_total_method' => 'unavailable',
        ];
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
