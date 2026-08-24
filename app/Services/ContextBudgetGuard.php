<?php

namespace App\Services;

use App\AgentRole;
use LogicException;

final class ContextBudgetGuard
{
    private const string ReductionMethod = 'fixed_quota_safe_boundary_v1';

    /** @var list<string> */
    private const array RepositoryRetrievalKeys = [
        'attachments',
        'linked_context',
        'related_context',
        'approved_documentation',
        'project_runtime_capabilities',
        'retrieval_manifest',
        'relevant_paths',
        'repository_context',
        'targeted_repository_context',
        'retrieved_repository_context',
    ];

    /** @var list<string> */
    private const array OlderHistoryKeys = [
        'older_history',
        'retry_history',
        'recovery_history',
        'attempt_history',
        'prior_attempts',
        'recent_history',
    ];

    /** @var list<string> */
    private const array CriticalEvidenceKeys = [
        'previous_attempt',
        'review_findings',
        'validation_evidence',
        'current_failure_evidence',
    ];

    /**
     * Inject the policy, estimator, and deterministic context assembler used by the budget boundary.
     */
    public function __construct(
        private ContextBudgetPolicy $policy,
        private ContextCostEstimator $costEstimator,
        private AgentContextAssembler $contextAssembler,
    ) {}

    /**
     * Evaluate one provider prompt against the locked Context Budget policy and return the approved or blocked result.
     *
     * @param  array<string, mixed>  $capacityEvidence
     * @param  array<string, mixed>|null  $persistedPolicyEvidence
     */
    public function evaluate(
        AgentRole $role,
        string $prompt,
        AssembledAgentContext $context,
        array $capacityEvidence,
        ?array $persistedPolicyEvidence = null,
        ?int $projectTargetPercent = null,
    ): ContextBudgetDecision {
        $capacityTokens = $this->positiveInt(
            $capacityEvidence['resolved_capacity_tokens'] ?? null,
            'Context Budget capacity evidence is missing a positive resolved capacity.',
        );

        $policy = $persistedPolicyEvidence === null
            ? $this->policy->resolve(
                $role,
                $capacityTokens,
                $projectTargetPercent,
            )
            : $this->restorePolicy(
                $role,
                $capacityTokens,
                $persistedPolicyEvidence,
            );

        $originalPromptTokens = $this->tokens($prompt);

        $requiredPrompt = $this->replaceContextInPrompt(
            $prompt,
            $this->requiredOnlyContext($context),
        );

        $requiredTokens = $this->tokens($requiredPrompt);

        $originalContributions = $this->sourceContributions(
            $context,
            $prompt,
        );

        $reductions = [];
        $finalContext = $context;
        $finalPrompt = $prompt;

        $warningReason =
            $originalPromptTokens >= $policy['warning_tokens']
                ? 'estimated_context_at_or_above_warning_threshold'
                : null;

        $blockReason = null;

        if ($requiredTokens >= $policy['hard_ceiling_tokens']) {
            $blockReason =
                'required_context_reaches_or_exceeds_hard_ceiling';
        } elseif (
            $originalPromptTokens >= $policy['warning_tokens']
        ) {
            [$finalContext, $finalPrompt, $reductions] =
                $this->reduce(
                    $context,
                    $prompt,
                    $policy,
                );
        }

        $finalTokens = $this->tokens($finalPrompt);

        if (
            $blockReason === null
            && $finalTokens >= $policy['hard_ceiling_tokens']
        ) {
            $blockReason =
                'context_still_reaches_or_exceeds_hard_ceiling_after_deterministic_reduction';
        }

        $blocked = $blockReason !== null;

        $finalContributions = $this->sourceContributions(
            $finalContext,
            $finalPrompt,
        );

        $reducedSources = array_values(
            array_unique(
                array_map(
                    fn (array $reduction): string => (string) $reduction['source'],
                    $reductions,
                ),
            ),
        );

        $excludedSources = [];

        foreach (
            $originalContributions as $source => $measurement
        ) {
            if (
                $measurement['estimated_tokens'] > 0
                && (
                    $finalContributions[$source]['estimated_tokens']
                    ?? 0
                ) === 0
            ) {
                $excludedSources[] = $source;
            }
        }

        $includedSources = [];

        foreach (
            $finalContributions as $source => $measurement
        ) {
            if ($measurement['estimated_tokens'] > 0) {
                $includedSources[] = $source;
            }
        }

        $evidence = [
            'schema_version' => ContextBudgetPolicy::SchemaVersion,
            'policy_version' => $policy['policy_version'],
            'capacity_source' => $capacityEvidence['capacity_source'] ?? null,
            'capacity_source_version' => $capacityEvidence['capacity_source_version']
                    ?? null,
            'capacity_fallback' => (bool) ($capacityEvidence['fallback'] ?? false),
            'harness' => $capacityEvidence['harness'] ?? null,
            'model' => $capacityEvidence['model'] ?? null,
            'resolved_capacity_tokens' => $capacityTokens,
            'max_output_tokens' => $capacityEvidence['max_output_tokens'] ?? null,
            'role' => $role->value,
            'target_source' => $policy['target_source'],
            'role_target_percent' => $policy['role_target_percent'],
            'project_target_percent' => $policy['project_target_percent'],
            'target_percent' => $policy['target_percent'],
            'warning_percent' => $policy['warning_percent'],
            'hard_ceiling_percent' => $policy['hard_ceiling_percent'],
            'reserved_percent' => $policy['reserved_percent'],
            'budget_tokens' => $policy['target_tokens'],
            'warning_tokens' => $policy['warning_tokens'],
            'hard_ceiling_tokens' => $policy['hard_ceiling_tokens'],
            'source_quota_percents' => $policy['source_quota_percents'],
            'source_quota_tokens' => $policy['source_quota_tokens'],
            'original_estimated_tokens' => $originalPromptTokens,
            'final_estimated_tokens' => $finalTokens,
            'required_estimated_tokens' => $requiredTokens,
            'source_contributions' => [
                'original' => $originalContributions,
                'final' => $finalContributions,
            ],
            'included_sources' => $includedSources,
            'reduced_sources' => $reducedSources,
            'excluded_sources' => $excludedSources,
            'reductions' => $reductions,
            'reduction_method' => self::ReductionMethod,
            'reduction_reason' => $reductions === []
                    ? null
                    : 'warning_threshold_reached_reduce_toward_normal_target',
            'utilization_before' => $this->utilization(
                $originalPromptTokens,
                $capacityTokens,
            ),
            'utilization_after' => $this->utilization(
                $finalTokens,
                $capacityTokens,
            ),
            'warning_reason' => $warningReason,
            'block_reason' => $blockReason,
            'decision' => $blocked
                    ? 'blocked'
                    : (
                        $reductions === []
                            ? 'approved'
                            : 'reduced'
                    ),
            'original_context_hash' => $context->hash,
            'final_context_hash' => $finalContext->hash,
            'original_prompt_hash' => hash('sha256', $prompt),
            'final_prompt_hash' => hash('sha256', $finalPrompt),
        ];

        return new ContextBudgetDecision(
            prompt: $finalPrompt,
            context: $finalContext,
            evidence: $evidence,
            blocked: $blocked,
        );
    }

    /**
     * Rehydrate the assembled AIOS context embedded in one managed provider prompt.
     */
    public function contextFromPrompt(
        string $prompt,
    ): AssembledAgentContext {
        $decoded =
            $this->promptPayload($prompt)['payload'];

        $assembled =
            is_array($decoded['task'] ?? null)
            && array_key_exists(
                'context_schema_version',
                $decoded['task'],
            )
                ? $decoded['task']
                : $decoded;

        return $this->contextAssembler->fromPayload(
            $assembled,
        );
    }

    /**
     * Replace the assembled context inside the existing managed prompt envelope without changing its workflow contract.
     */
    public function promptWithContext(
        string $prompt,
        AssembledAgentContext $context,
    ): string {
        return $this->replaceContextInPrompt(
            $prompt,
            $context,
        );
    }

    /**
     * Apply the locked optional-context reduction sequence.
     *
     * @param  array<string, mixed>  $policy
     * @return array{
     *     0: AssembledAgentContext,
     *     1: string,
     *     2: list<array<string, mixed>>
     * }
     */
    private function reduce(
        AssembledAgentContext $context,
        string $prompt,
        array $policy,
    ): array {
        $agent = $context->agentSnapshot;
        $skills = $context->skillsSnapshot;
        $task = $context->taskContext;

        $reductions = [];
        $current = $context;
        $currentPrompt = $prompt;

        $apply = function (
            string $source,
            int $quotaTokens,
            int $currentSourceTokens,
            callable $mutate,
        ) use (
            &$agent,
            &$skills,
            &$task,
            &$reductions,
            &$current,
            &$currentPrompt,
            $policy,
        ): void {
            $currentTotal =
                $this->tokens($currentPrompt);

            $tokensNeeded = max(
                0,
                $currentTotal - $policy['target_tokens'],
            );

            if (
                $tokensNeeded === 0
                || $currentSourceTokens <= $quotaTokens
            ) {
                return;
            }

            $allowedTokens = max(
                $quotaTokens,
                $currentSourceTokens - $tokensNeeded,
            );

            $before = $currentSourceTokens;

            $mutate($allowedTokens);

            $current =
                $this->contextAssembler->rebuild(
                    $current,
                    $agent,
                    $skills,
                    $task,
                );

            $currentPrompt =
                $this->replaceContextInPrompt(
                    $currentPrompt,
                    $current,
                );

            $afterContributions =
                $this->sourceContributions(
                    $current,
                    $currentPrompt,
                );

            $after = (int) (
                $afterContributions[$source]['estimated_tokens']
                ?? 0
            );

            if ($after < $before) {
                $reductions[] = [
                    'source' => $source,
                    'before_estimated_tokens' => $before,
                    'after_estimated_tokens' => $after,
                    'quota_tokens' => $quotaTokens,
                    'method' => self::ReductionMethod,
                ];
            }
        };

        $contributions =
            $this->sourceContributions(
                $current,
                $currentPrompt,
            );

        $apply(
            'agent_default_context',
            $policy['source_quota_tokens'][
                'agent_default_context'
            ],
            (int) $contributions[
                'agent_default_context'
            ]['estimated_tokens'],
            function (int $allowedTokens) use (
                &$agent,
            ): void {
                $value =
                    $agent['default_context'] ?? null;

                $agent['default_context'] =
                    is_string($value)
                        ? $this->safeTextPrefix(
                            $value,
                            $allowedTokens * 4,
                        )
                        : null;
            },
        );

        $contributions =
            $this->sourceContributions(
                $current,
                $currentPrompt,
            );

        $apply(
            'skills',
            $policy['source_quota_tokens']['skills'],
            (int) $contributions[
                'skills'
            ]['estimated_tokens'],
            function (int $allowedTokens) use (
                &$skills,
            ): void {
                $skills = $this->fitSkills(
                    $skills,
                    $allowedTokens * 4,
                );
            },
        );

        $contributions =
            $this->sourceContributions(
                $current,
                $currentPrompt,
            );

        $apply(
            'repository_retrieval',
            $policy['source_quota_tokens'][
                'repository_retrieval'
            ],
            (int) $contributions[
                'repository_retrieval'
            ]['estimated_tokens'],
            function (int $allowedTokens) use (
                &$task,
            ): void {
                $task = $this->fitTaskKeys(
                    $task,
                    self::RepositoryRetrievalKeys,
                    $allowedTokens * 4,
                );
            },
        );

        $contributions =
            $this->sourceContributions(
                $current,
                $currentPrompt,
            );

        $apply(
            'obsidian_context',
            $policy['source_quota_tokens'][
                'obsidian_context'
            ],
            (int) $contributions[
                'obsidian_context'
            ]['estimated_tokens'],
            function (int $allowedTokens) use (
                &$task,
            ): void {
                if (
                    array_key_exists(
                        'obsidian_project_knowledge',
                        $task,
                    )
                ) {
                    $task['obsidian_project_knowledge'] =
                        $this->fitValue(
                            $task[
                                'obsidian_project_knowledge'
                            ],
                            $allowedTokens * 4,
                        );
                }
            },
        );

        $contributions =
            $this->sourceContributions(
                $current,
                $currentPrompt,
            );

        $apply(
            'older_history',
            $policy['source_quota_tokens'][
                'older_history'
            ],
            (int) $contributions[
                'older_history'
            ]['estimated_tokens'],
            function (int $allowedTokens) use (
                &$task,
            ): void {
                $task = $this->fitTaskKeys(
                    $task,
                    self::OlderHistoryKeys,
                    $allowedTokens * 4,
                );
            },
        );

        return [
            $current,
            $currentPrompt,
            $reductions,
        ];
    }

    /**
     * Build the non-reducible context used by the hard-ceiling decision.
     */
    private function requiredOnlyContext(
        AssembledAgentContext $context,
    ): AssembledAgentContext {
        $agent = $context->agentSnapshot;
        $agent['default_context'] = null;

        $task = $context->taskContext;

        foreach (
            [
                ...self::RepositoryRetrievalKeys,
                ...self::OlderHistoryKeys,
                'obsidian_project_knowledge',
            ] as $key
        ) {
            unset($task[$key]);
        }

        return $this->contextAssembler->rebuild(
            $context,
            $agent,
            [],
            $task,
        );
    }

    /**
     * Measure each context source independently, including required Agent handoff evidence.
     *
     * @return array<
     *     string,
     *     array{characters: int, estimated_tokens: int}
     * >
     */
    private function sourceContributions(
        AssembledAgentContext $context,
        string $prompt,
    ): array {
        $task = $context->taskContext;

        $repository = $this->valuesForKeys(
            $task,
            self::RepositoryRetrievalKeys,
        );

        $history = $this->valuesForKeys(
            $task,
            self::OlderHistoryKeys,
        );

        $critical = $this->valuesForKeys(
            $task,
            self::CriticalEvidenceKeys,
        );

        $handoffs =
            $task[
                AgentHandoffContextSelector::ContextKey
            ] ?? null;

        $excluded = array_fill_keys(
            [
                ...self::RepositoryRetrievalKeys,
                ...self::OlderHistoryKeys,
                ...self::CriticalEvidenceKeys,
                'obsidian_project_knowledge',
                AgentHandoffContextSelector::ContextKey,
            ],
            true,
        );

        $requiredCore =
            array_diff_key(
                $task,
                $excluded,
            );

        $contextPayload =
            $this->encode($context->toArray());

        $promptMeasurement =
            $this->costEstimator->measureValue(
                $prompt,
            );

        $contextMeasurement =
            $this->costEstimator->measureValue(
                $contextPayload,
            );

        $workflowCharacters = max(
            0,
            $promptMeasurement['characters']
                - $contextMeasurement['characters'],
        );

        $skills =
            $this->costEstimator->measureValue(
                $context->skillsSnapshot,
            );

        return [
            'workflow_contract' => [
                'characters' => $workflowCharacters,
                'estimated_tokens' => (int) ceil(
                    $workflowCharacters / 4,
                ),
            ],
            'system_rules' => $this->costEstimator->measureValue(
                $context->systemRules,
            ),
            'agent_default_context' => $this->costEstimator->measureValue(
                $context->agentSnapshot[
                    'default_context'
                ] ?? null,
            ),
            'skills' => $skills,
            'repository_retrieval' => $this->costEstimator->measureValue(
                $repository,
            ),
            'obsidian_context' => $this->costEstimator->measureValue(
                $task[
                    'obsidian_project_knowledge'
                ] ?? null,
            ),
            'older_history' => $this->costEstimator->measureValue(
                $history,
            ),
            'critical_current_evidence' => $this->costEstimator->measureValue(
                $critical,
            ),
            'agent_handoffs' => $this->costEstimator->measureValue(
                $handoffs,
            ),
            'task_required_core' => $this->costEstimator->measureValue(
                $requiredCore,
            ),
        ];
    }

    /**
     * Fit deterministic Skill content inside the assigned quota.
     *
     * @param  list<array<string, mixed>>  $skills
     * @return list<array<string, mixed>>
     */
    private function fitSkills(
        array $skills,
        int $characterBudget,
    ): array {
        if ($characterBudget <= 0) {
            return [];
        }

        $remaining = $characterBudget;
        $result = [];

        foreach ($skills as $skill) {
            $base = array_diff_key(
                $skill,
                [
                    'instructions' => true,
                    'constraints' => true,
                ],
            );

            $baseCharacters =
                $this->costEstimator->measureValue(
                    $base,
                )['characters'];

            if ($baseCharacters >= $remaining) {
                break;
            }

            $remaining -= $baseCharacters;

            $instructions =
                is_string(
                    $skill['instructions'] ?? null,
                )
                    ? $skill['instructions']
                    : '';

            $constraints =
                is_string(
                    $skill['constraints'] ?? null,
                )
                    ? $skill['constraints']
                    : '';

            $instructionCharacters =
                mb_strlen($instructions);

            $keptInstructions =
                $this->safeTextPrefix(
                    $instructions,
                    min(
                        $remaining,
                        $instructionCharacters,
                    ),
                );

            $remaining -=
                mb_strlen($keptInstructions);

            $keptConstraints =
                $this->safeTextPrefix(
                    $constraints,
                    $remaining,
                );

            $remaining -=
                mb_strlen($keptConstraints);

            $result[] = [
                ...$base,
                'instructions' => $keptInstructions,
                'constraints' => $keptConstraints === ''
                        ? null
                        : $keptConstraints,
            ];

            if ($remaining <= 0) {
                break;
            }
        }

        return $result;
    }

    /**
     * Fit only the named optional Task-context keys within one deterministic character budget.
     *
     * @param  array<string, mixed>  $task
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function fitTaskKeys(
        array $task,
        array $keys,
        int $characterBudget,
    ): array {
        $remaining =
            max(0, $characterBudget);

        foreach ($keys as $key) {
            if (! array_key_exists($key, $task)) {
                continue;
            }

            if ($remaining === 0) {
                $task[$key] = null;

                continue;
            }

            $fitted =
                $this->fitValue(
                    $task[$key],
                    $remaining,
                );

            $task[$key] = $fitted;

            $remaining = max(
                0,
                $remaining
                    - $this->costEstimator
                        ->measureValue(
                            $fitted,
                        )['characters'],
            );
        }

        return $task;
    }

    /**
     * Fit one optional value while preserving valid bounded text or a deterministic excerpt.
     */
    private function fitValue(
        mixed $value,
        int $characterBudget,
    ): mixed {
        if (
            $characterBudget <= 0
            || $value === null
        ) {
            return null;
        }

        $measurement =
            $this->costEstimator->measureValue(
                $value,
            );

        if (
            $measurement['characters']
            <= $characterBudget
        ) {
            return $value;
        }

        if (is_string($value)) {
            return $this->safeTextPrefix(
                $value,
                $characterBudget,
            );
        }

        $encoded =
            $this->encode($value);

        $marker =
            '[AIOS deterministic context reduction excerpt] ';

        $excerptBudget = max(
            0,
            $characterBudget
                - mb_strlen($marker),
        );

        return $marker
            .$this->safeTextPrefix(
                $encoded,
                $excerptBudget,
            );
    }

    /**
     * Truncate optional text at a stable whitespace boundary.
     */
    private function safeTextPrefix(
        string $value,
        int $characters,
    ): string {
        if (
            $characters <= 0
            || $value === ''
        ) {
            return '';
        }

        if (
            mb_strlen($value)
            <= $characters
        ) {
            return $value;
        }

        $slice =
            mb_substr(
                $value,
                0,
                $characters,
            );

        $boundary =
            preg_replace(
                '/\s+\S*$/u',
                '',
                $slice,
            );

        return is_string($boundary)
            && $boundary !== ''
                ? rtrim($boundary)
                : rtrim($slice);
    }

    /**
     * Collect only named Task-context values.
     *
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function valuesForKeys(
        array $source,
        array $keys,
    ): array {
        $values = [];

        foreach ($keys as $key) {
            if (
                array_key_exists(
                    $key,
                    $source,
                )
            ) {
                $values[$key] =
                    $source[$key];
            }
        }

        return $values;
    }

    /**
     * Replace only the assembled-context JSON while retaining the existing workflow prompt prefix.
     */
    private function replaceContextInPrompt(
        string $prompt,
        AssembledAgentContext $context,
    ): string {
        $parsed =
            $this->promptPayload($prompt);

        $payload =
            $parsed['payload'];

        if (
            is_array(
                $payload['task'] ?? null,
            )
            && array_key_exists(
                'context_schema_version',
                $payload['task'],
            )
        ) {
            $payload['task'] =
                $context->toArray();
        } elseif (
            array_key_exists(
                'context_schema_version',
                $payload,
            )
        ) {
            $payload =
                $context->toArray();
        } else {
            throw new LogicException(
                'The provider prompt JSON envelope does not contain assembled Agent context.',
            );
        }

        return $parsed['prefix']
            .$this->encode($payload);
    }

    /**
     * Parse the supported managed-prompt JSON envelope.
     *
     * @return array{
     *     prefix: string,
     *     payload: array<string, mixed>
     * }
     */
    private function promptPayload(
        string $prompt,
    ): array {
        $ticketMarker =
            "AIOS assembled context:\n";

        $markerPosition =
            strrpos(
                $prompt,
                $ticketMarker,
            );

        if ($markerPosition !== false) {
            $start =
                $markerPosition
                    + strlen($ticketMarker);

            $prefix =
                substr(
                    $prompt,
                    0,
                    $start,
                );

            $json =
                substr(
                    $prompt,
                    $start,
                );
        } else {
            $delimiterPosition =
                strrpos(
                    $prompt,
                    "\n\n{",
                );

            if ($delimiterPosition === false) {
                throw new LogicException(
                    'The provider prompt has no deterministic assembled-context JSON boundary.',
                );
            }

            $start =
                $delimiterPosition + 2;

            $prefix =
                substr(
                    $prompt,
                    0,
                    $start,
                );

            $json =
                substr(
                    $prompt,
                    $start,
                );
        }

        $decoded =
            json_decode(
                $json,
                true,
            );

        if (! is_array($decoded)) {
            throw new LogicException(
                'The provider prompt assembled-context JSON is malformed.',
            );
        }

        return [
            'prefix' => $prefix,
            'payload' => $decoded,
        ];
    }

    /**
     * Restore exact persisted policy evidence for the same interrupted execution.
     *
     * @param  array<string, mixed>  $persisted
     * @return array<string, mixed>
     */
    private function restorePolicy(
        AgentRole $role,
        int $capacityTokens,
        array $persisted,
    ): array {
        if (
            ($persisted['role'] ?? null)
                !== $role->value
            || (
                $persisted[
                    'resolved_capacity_tokens'
                ] ?? null
            ) !== $capacityTokens
        ) {
            throw new LogicException(
                'Persisted Context Budget evidence does not match the recovered role/capacity.',
            );
        }

        $schemaVersion =
            $persisted['schema_version']
                ?? ContextBudgetPolicy::SchemaVersion;

        if (! is_int($schemaVersion)) {
            throw new LogicException(
                'Persisted Context Budget evidence has an invalid [schema_version].',
            );
        }

        return [
            'schema_version' => $schemaVersion,
            'policy_version' => $this->requiredInt(
                $persisted,
                'policy_version',
            ),
            'role' => $role->value,
            'role_target_percent' => $this->requiredInt(
                $persisted,
                'role_target_percent',
            ),
            'project_target_percent' => $this->nullableInt(
                $persisted,
                'project_target_percent',
            ),
            'target_source' => $this->requiredString(
                $persisted,
                'target_source',
            ),
            'target_percent' => $this->requiredInt(
                $persisted,
                'target_percent',
            ),
            'warning_percent' => $this->requiredInt(
                $persisted,
                'warning_percent',
            ),
            'hard_ceiling_percent' => $this->requiredInt(
                $persisted,
                'hard_ceiling_percent',
            ),
            'reserved_percent' => $this->requiredInt(
                $persisted,
                'reserved_percent',
            ),
            'capacity_tokens' => $capacityTokens,
            'target_tokens' => $this->requiredInt(
                $persisted,
                'budget_tokens',
            ),
            'warning_tokens' => $this->requiredInt(
                $persisted,
                'warning_tokens',
            ),
            'hard_ceiling_tokens' => $this->requiredInt(
                $persisted,
                'hard_ceiling_tokens',
            ),
            'source_quota_percents' => $this->requiredIntMap(
                $persisted,
                'source_quota_percents',
            ),
            'source_quota_tokens' => $this->requiredIntMap(
                $persisted,
                'source_quota_tokens',
            ),
        ];
    }

    /**
     * Read one required integer.
     *
     * @param  array<string, mixed>  $source
     */
    private function requiredInt(
        array $source,
        string $key,
    ): int {
        $value =
            $source[$key] ?? null;

        if (! is_int($value)) {
            throw new LogicException(
                "Persisted Context Budget evidence has an invalid [{$key}].",
            );
        }

        return $value;
    }

    /**
     * Read one nullable integer.
     *
     * @param  array<string, mixed>  $source
     */
    private function nullableInt(
        array $source,
        string $key,
    ): ?int {
        if (
            ! array_key_exists(
                $key,
                $source,
            )
            || $source[$key] === null
        ) {
            return null;
        }

        $value = $source[$key];

        if (! is_int($value)) {
            throw new LogicException(
                "Persisted Context Budget evidence has an invalid [{$key}].",
            );
        }

        return $value;
    }

    /**
     * Read one required string.
     *
     * @param  array<string, mixed>  $source
     */
    private function requiredString(
        array $source,
        string $key,
    ): string {
        $value =
            $source[$key] ?? null;

        if (
            ! is_string($value)
            || $value === ''
        ) {
            throw new LogicException(
                "Persisted Context Budget evidence has an invalid [{$key}].",
            );
        }

        return $value;
    }

    /**
     * Read one required string-to-integer map.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, int>
     */
    private function requiredIntMap(
        array $source,
        string $key,
    ): array {
        $value =
            $source[$key] ?? null;

        if (! is_array($value)) {
            throw new LogicException(
                "Persisted Context Budget evidence has an invalid [{$key}].",
            );
        }

        foreach (
            $value as $mapKey => $mapValue
        ) {
            if (
                ! is_string($mapKey)
                || ! is_int($mapValue)
            ) {
                throw new LogicException(
                    "Persisted Context Budget evidence has an invalid [{$key}].",
                );
            }
        }

        return $value;
    }

    /**
     * Estimate tokens with the existing deterministic estimator.
     */
    private function tokens(
        string $value,
    ): int {
        return $this->costEstimator
            ->measureValue(
                $value,
            )['estimated_tokens'];
    }

    /**
     * Calculate stable provider-capacity utilization.
     */
    private function utilization(
        int $tokens,
        int $capacity,
    ): float {
        return round(
            ($tokens / $capacity) * 100,
            4,
        );
    }

    /**
     * Require a positive integer at a budget boundary.
     */
    private function positiveInt(
        mixed $value,
        string $message,
    ): int {
        if (
            ! is_int($value)
            || $value <= 0
        ) {
            throw new LogicException(
                $message,
            );
        }

        return $value;
    }

    /**
     * Encode deterministic JSON.
     */
    private function encode(
        mixed $value,
    ): string {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }
}
