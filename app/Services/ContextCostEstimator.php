<?php

namespace App\Services;

/**
 * Estimates the preflight character/token cost of each meaningful section of an assembled
 * Agent execution context, so disproportionately large context sources can be diagnosed before
 * a harness run executes rather than only after the fact via post-execution token usage.
 *
 * Measurement is deterministic and provider-neutral: characters are counted directly (or via
 * json_encode for structured values), and estimated_tokens is a fixed characters/4 heuristic
 * (~4 characters per token for English text) rather than a real tokenizer, per the "deterministic,
 * bounded, inexpensive, no external tokenizer" requirement.
 */
class ContextCostEstimator
{
    public const int SchemaVersion = 1;

    private const int CharactersPerToken = 4;

    /**
     * @param  array<string, mixed>  $agentSnapshot
     * @param  list<array<string, mixed>>  $skillsSnapshot
     * @param  array<string, mixed>  $taskContext
     * @return array<string, mixed>
     */
    public function estimate(string $systemRules, array $agentSnapshot, array $skillsSnapshot, array $taskContext): array
    {
        $sections = [
            'system_rules' => $this->measure($systemRules),
            'agent_default_context' => $this->measure($agentSnapshot['default_context'] ?? null),
        ];

        $skills = [];

        foreach ($skillsSnapshot as $skill) {
            $measured = $this->measure([
                'instructions' => $skill['instructions'] ?? null,
                'constraints' => $skill['constraints'] ?? null,
            ]);

            $skills[] = [
                'slug' => $skill['slug'] ?? null,
                'position' => $skill['position'] ?? null,
                'characters' => $measured['characters'],
                'estimated_tokens' => $measured['estimated_tokens'],
            ];
        }

        $sections['skills_total'] = $this->sum(array_map(
            fn (array $skill): array => ['characters' => $skill['characters'], 'estimated_tokens' => $skill['estimated_tokens']],
            $skills,
        ));

        $taskContextBuckets = $this->bucketTaskContext($taskContext);

        foreach ($taskContextBuckets as $key => $value) {
            $sections[$key] = $this->measure($value);
        }

        $total = $this->sum(array_values($sections));

        return [
            'schema_version' => self::SchemaVersion,
            'characters_per_token_ratio' => self::CharactersPerToken,
            'system_rules' => $sections['system_rules'],
            'agent_default_context' => $sections['agent_default_context'],
            'skills' => $skills,
            'skills_total' => $sections['skills_total'],
            'task_core' => $sections['task_core'],
            'obsidian_context' => $sections['obsidian_context'],
            'retry_recovery_evidence' => $sections['retry_recovery_evidence'],
            'review_evidence' => $sections['review_evidence'],
            'total' => $total,
            'disproportionate_sections' => $this->disproportionateSections($sections, $total),
        ];
    }

    /** @return array{characters: int, estimated_tokens: int} */
    public function measureValue(mixed $value): array
    {
        return $this->measure($value);
    }

    /**
     * @param  array<string, mixed>  $taskContext
     * @return array{task_core: array<string, mixed>, obsidian_context: mixed, retry_recovery_evidence: mixed, review_evidence: mixed}
     */
    private function bucketTaskContext(array $taskContext): array
    {
        $obsidian = $taskContext['obsidian_project_knowledge'] ?? null;
        $retryRecovery = $taskContext['previous_attempt'] ?? null;
        $review = $taskContext['review_findings'] ?? null;

        $taskCore = array_diff_key($taskContext, [
            'obsidian_project_knowledge' => true,
            'previous_attempt' => true,
            'review_findings' => true,
        ]);

        return [
            'task_core' => $taskCore,
            'obsidian_context' => $obsidian,
            'retry_recovery_evidence' => $retryRecovery,
            'review_evidence' => $review,
        ];
    }

    /** @return array{characters: int, estimated_tokens: int} */
    private function measure(mixed $value): array
    {
        $characters = match (true) {
            $value === null => 0,
            is_string($value) => mb_strlen($value),
            is_array($value) && $value === [] => 0,
            default => mb_strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        };

        return [
            'characters' => $characters,
            'estimated_tokens' => (int) ceil($characters / self::CharactersPerToken),
        ];
    }

    /**
     * @param  list<array{characters: int, estimated_tokens: int}>  $measurements
     * @return array{characters: int, estimated_tokens: int}
     */
    private function sum(array $measurements): array
    {
        return [
            'characters' => array_sum(array_column($measurements, 'characters')),
            'estimated_tokens' => array_sum(array_column($measurements, 'estimated_tokens')),
        ];
    }

    /**
     * @param  array<string, array{characters: int, estimated_tokens: int}>  $sections
     * @param  array{characters: int, estimated_tokens: int}  $total
     * @return list<string>
     */
    private function disproportionateSections(array $sections, array $total): array
    {
        if ($total['characters'] === 0) {
            return [];
        }

        $threshold = (float) config('aios.context_cost_warning_share', 0.5);
        $flagged = [];

        foreach ($sections as $key => $measurement) {
            if ($key === 'skills_total') {
                continue;
            }

            if ($threshold < $measurement['characters'] / $total['characters']) {
                $flagged[] = $key;
            }
        }

        return $flagged;
    }
}
