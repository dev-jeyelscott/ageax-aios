<?php

namespace App\Services;

use App\Actions\ConsumeAgentHandoffs;
use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\TaskAttempt;
use Closure;
use LogicException;
use Throwable;

/**
 * AIOS-owned execution gate shared by provider harnesses.
 *
 * Normal managed execution creates the durable AgentRun before the provider harness is invoked.
 * Raw calls without a matching AgentRun remain only the existing provider-adapter compatibility path.
 */
final readonly class ContextBudgetedAgentHarness
{
    /**
     * Inject deterministic context, handoff, budget, consumption, and audit boundaries.
     */
    public function __construct(
        private ContextBudgetGuard $guard,
        private AgentContextAssembler $contextAssembler,
        private AgentHandoffContextSelector $handoffs,
        private ConsumeAgentHandoffs $consumeHandoffs,
        private AuditLogger $audit,
    ) {}

    /**
     * Select handoffs, budget the final context, consume delivered evidence, then dispatch the provider.
     *
     * @param  Closure(string, ?Closure, ?Closure, array<string, mixed>): NormalizedExecutionResult  $provider
     */
    public function execute(
        AgentHarness $harness,
        Project $project,
        Agent $agent,
        string $prompt,
        Closure $provider,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        array $executionSettings = [],
    ): NormalizedExecutionResult {
        $run = $this->currentRun(
            $project,
            $agent,
            $prompt,
        );

        if ($run === null) {
            if ($this->looksLikeManagedPrompt($prompt)) {
                return $this->failure(
                    $harness->identifier(),
                    'Context Budget preflight evidence cannot be attached because no matching running AgentRun exists.',
                    'context_budget_run_missing',
                );
            }

            return $provider(
                $prompt,
                $onOutput,
                $onHeartbeat,
                $executionSettings,
            );
        }

        try {
            $context =
                $this->guard->contextFromPrompt(
                    $prompt,
                );

            $recoverySource =
                $this->recoverySource($run);

            $handoffSelection =
                $this->handoffs->select(
                    $run,
                    $recoverySource,
                );

            if (
                $handoffSelection['entries']
                !== []
            ) {
                $context =
                    $this->injectHandoffContext(
                        $context,
                        $handoffSelection[
                            'entries'
                        ],
                    );

                $prompt =
                    $this->guard
                        ->promptWithContext(
                            $prompt,
                            $context,
                        );
            }

            $capacity =
                $harness
                    ->capabilities()
                    ->resolveContextCapacity(
                        $agent,
                        $harness->identifier(),
                    );

            $persistedPolicy = null;

            if ($recoverySource !== null) {
                $snapshot =
                    $this->arrayAttribute(
                        $recoverySource,
                        'context_budget_snapshot',
                    );

                if ($snapshot === null) {
                    throw new LogicException(
                        'Recovered Context Budget evidence is missing.',
                    );
                }

                $capacity =
                    $this->capacityFromSnapshot(
                        $snapshot,
                        $harness->identifier(),
                    );

                $persistedPolicy =
                    $snapshot;
            }

            $decision =
                $this->guard->evaluate(
                    $this->role($run),
                    $prompt,
                    $context,
                    $capacity,
                    $persistedPolicy,
                );

            $finalContext =
                $decision->context;

            if ($finalContext === null) {
                throw new LogicException(
                    'Context Budget approval did not retain the final assembled Agent context.',
                );
            }

            if (
                $this->handoffs
                    ->idsFromContext(
                        $finalContext,
                    )
                    !== $handoffSelection[
                        'handoff_ids'
                    ]
                || $this->handoffs
                    ->contentHashesFromContext(
                        $finalContext,
                    )
                    !== $handoffSelection[
                        'content_hashes'
                    ]
            ) {
                throw new LogicException(
                    'Final Context Budget output changed required Agent handoff evidence.',
                );
            }
        } catch (Throwable $throwable) {
            $this->audit->record(
                'context_budget.blocked',
                [
                    'agent_run_id' => $run->id,
                    'reason' => 'context_budget_preflight_failed',
                    'error' => $throwable->getMessage(),
                ],
                $project,
                $run->task,
            );

            return $this->failure(
                $harness->identifier(),
                'Context Budget preflight failed safely: '
                    .$throwable->getMessage(),
                'context_budget_preflight_failed',
            );
        }

        $evidence = [
            ...$decision->evidence,
            'recovery_snapshot_reused' => $recoverySource !== null,
            'recovery_snapshot_source_run_id' => $recoverySource?->id,
            'agent_handoff_ids' => $handoffSelection[
                    'handoff_ids'
                ],
            'agent_handoff_pending_ids' => $handoffSelection[
                    'pending_handoff_ids'
                ],
            'agent_handoff_content_hashes' => $handoffSelection[
                    'content_hashes'
                ],
            'agent_handoff_replay_source_agent_run_id' => $handoffSelection[
                    'replay_source_agent_run_id'
                ],
        ];

        $run->update([
            'prompt_hash' => $evidence['final_prompt_hash'],
            'configuration_snapshot' => $finalContext
                ->configurationSnapshot(),
            'context_schema_version' => $finalContext
                ->contextSchemaVersion,
            'context_cost_estimate' => $finalContext
                ->contextCostEstimate,
            'context_cost_schema_version' => $finalContext
                ->contextCostSchemaVersion,
            'context_budget_snapshot' => $evidence,
            'context_budget_schema_version' => ContextBudgetPolicy::SchemaVersion,
        ]);

        $this->recordEvidence(
            $run->refresh(),
            $evidence,
        );

        if ($decision->blocked) {
            return $this->failure(
                $harness->identifier(),
                'Context Budget blocked provider execution: '.(
                    $evidence['block_reason']
                        ?? 'hard ceiling reached.'
                ),
                'context_budget_blocked',
            );
        }

        try {
            $this->consumeHandoffs->handle(
                $run->refresh(),
                $handoffSelection[
                    'pending_handoff_ids'
                ],
                (string) $evidence[
                    'final_context_hash'
                ],
                $recoverySource,
            );
        } catch (Throwable $throwable) {
            $this->audit->record(
                'agent_handoff.consumption_failed',
                [
                    'agent_run_id' => $run->id,
                    'handoff_ids' => $handoffSelection[
                            'pending_handoff_ids'
                        ],
                    'context_hash' => $evidence[
                            'final_context_hash'
                        ],
                    'error' => $throwable->getMessage(),
                ],
                $project,
                $run->task,
            );

            return $this->failure(
                $harness->identifier(),
                'Agent handoff consumption failed safely before provider dispatch: '
                    .$throwable->getMessage(),
                'agent_handoff_consumption_failed',
            );
        }

        return $provider(
            $decision->prompt,
            $onOutput,
            $onHeartbeat,
            $finalContext->executionSettings,
        );
    }

    /**
     * Locate the exact running AgentRun for the original pre-injection prompt.
     */
    private function currentRun(
        Project $project,
        Agent $agent,
        string $prompt,
    ): ?AgentRun {
        return AgentRun::query()
            ->whereBelongsTo($project)
            ->where('agent_id', $agent->id)
            ->where(
                'status',
                AgentRunStatus::Running,
            )
            ->where(
                'prompt_hash',
                hash('sha256', $prompt),
            )
            ->latest('id')
            ->first();
    }

    /**
     * Resolve the exact interrupted AgentRun whose persisted execution evidence this run recovers.
     */
    private function recoverySource(
        AgentRun $run,
    ): ?AgentRun {
        if (
            $run->task_id === null
            || $run->attempt_number === null
        ) {
            return null;
        }

        $configurationSnapshot =
            $this->arrayAttribute(
                $run,
                'configuration_snapshot',
            );

        if ($configurationSnapshot === null) {
            return null;
        }

        $sourceAttemptNumber =
            $this->recoverySourceAttemptNumber(
                $run,
            );

        if ($sourceAttemptNumber === null) {
            return null;
        }

        $candidate =
            AgentRun::query()
                ->where(
                    'project_id',
                    $run->project_id,
                )
                ->where(
                    'task_id',
                    $run->task_id,
                )
                ->where(
                    'role',
                    $run->getRawOriginal(
                        'role',
                    ),
                )
                ->where(
                    'id',
                    '<',
                    $run->id,
                )
                ->where(
                    'attempt_number',
                    $sourceAttemptNumber,
                )
                ->orderByDesc('id')
                ->first();

        if ($candidate === null) {
            return null;
        }

        if (
            $candidate->getRawOriginal(
                'status',
            )
            !== AgentRunStatus::Interrupted->value
        ) {
            return null;
        }

        if (
            $this->arrayAttribute(
                $candidate,
                'context_budget_snapshot',
            ) === null
        ) {
            return null;
        }

        $candidateSnapshot =
            $this->arrayAttribute(
                $candidate,
                'configuration_snapshot',
            );

        if (
            $candidateSnapshot === null
            || $candidateSnapshot
                !== $configurationSnapshot
        ) {
            return null;
        }

        return $candidate;
    }

    /**
     * Resolve the durable source attempt used by the existing Reviewer and Coder recovery semantics.
     */
    private function recoverySourceAttemptNumber(
        AgentRun $run,
    ): ?int {
        $role =
            $this->role($run);

        if ($role === AgentRole::Reviewer) {
            return (int) $run->attempt_number;
        }

        if ($role !== AgentRole::Coder) {
            return null;
        }

        $attempt =
            TaskAttempt::query()
                ->where(
                    'task_id',
                    $run->task_id,
                )
                ->where(
                    'number',
                    $run->attempt_number,
                )
                ->first();

        $rawValidationResults =
            $attempt?->getRawOriginal(
                'validation_results',
            );

        if ($rawValidationResults === null) {
            return null;
        }

        $validationResults =
            json_decode(
                $rawValidationResults,
                true,
            );

        if (! is_array($validationResults)) {
            return null;
        }

        $repositoryPreflight =
            $validationResults[
                'repository_preflight'
            ] ?? null;

        if (! is_array($repositoryPreflight)) {
            return null;
        }

        if (
            (
                $repositoryPreflight[
                    'mode'
                ] ?? null
            ) !== 'recovery'
        ) {
            return null;
        }

        $recoveryAttemptNumber =
            $repositoryPreflight[
                'recovery_attempt_number'
            ] ?? null;

        if (
            ! is_int($recoveryAttemptNumber)
            || $recoveryAttemptNumber < 1
            || $recoveryAttemptNumber
                >= (int) $run->attempt_number
        ) {
            return null;
        }

        return $recoveryAttemptNumber;
    }

    /**
     * Add selected handoffs to task context and recompute the deterministic context hash.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function injectHandoffContext(
        AssembledAgentContext $context,
        array $entries,
    ): AssembledAgentContext {
        $taskContext =
            $context->taskContext;

        if (
            array_key_exists(
                AgentHandoffContextSelector::ContextKey,
                $taskContext,
            )
        ) {
            throw new LogicException(
                'Managed prompts cannot supply Agent handoff context outside the AIOS selection boundary.',
            );
        }

        $taskContext[
            AgentHandoffContextSelector::ContextKey
        ] = $entries;

        return $this->contextAssembler->rebuild(
            $context,
            $context->agentSnapshot,
            $context->skillsSnapshot,
            $taskContext,
        );
    }

    /**
     * Resolve the persisted role for one AgentRun.
     */
    private function role(
        AgentRun $run,
    ): AgentRole {
        return AgentRole::from(
            (string) $run->getRawOriginal(
                'role',
            ),
        );
    }

    /**
     * Decode one persisted AgentRun JSON attribute.
     *
     * @return array<string, mixed>|null
     */
    private function arrayAttribute(
        AgentRun $run,
        string $attribute,
    ): ?array {
        $raw =
            $run->getRawOriginal(
                $attribute,
            );

        if ($raw === null) {
            return null;
        }

        $decoded =
            json_decode(
                (string) $raw,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

        if (! is_array($decoded)) {
            throw new LogicException(
                sprintf(
                    'AgentRun %s must contain a JSON object or array.',
                    $attribute,
                ),
            );
        }

        return $decoded;
    }

    /**
     * Restore provider-capacity evidence from the interrupted source snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function capacityFromSnapshot(
        array $snapshot,
        AgentHarnessIdentifier $identifier,
    ): array {
        $harness =
            $snapshot['harness']
                ?? $identifier->value;

        $model =
            $snapshot['model'] ?? null;

        $resolvedCapacity =
            $snapshot[
                'resolved_capacity_tokens'
            ] ?? null;

        $maxOutputTokens =
            $snapshot[
                'max_output_tokens'
            ] ?? null;

        $capacitySource =
            $snapshot[
                'capacity_source'
            ] ?? null;

        $capacitySourceVersion =
            $snapshot[
                'capacity_source_version'
            ] ?? null;

        $fallback =
            $snapshot[
                'capacity_fallback'
            ] ?? false;

        if (
            ! is_string($harness)
            || $harness === ''
        ) {
            throw new LogicException(
                'Recovered Context Budget evidence has an invalid harness.',
            );
        }

        if (
            $model !== null
            && ! is_string($model)
        ) {
            throw new LogicException(
                'Recovered Context Budget evidence has an invalid model.',
            );
        }

        if (
            ! is_int($resolvedCapacity)
            || $resolvedCapacity <= 0
        ) {
            throw new LogicException(
                'Recovered Context Budget evidence has an invalid resolved capacity.',
            );
        }

        if (
            $maxOutputTokens !== null
            && (
                ! is_int($maxOutputTokens)
                || $maxOutputTokens <= 0
            )
        ) {
            throw new LogicException(
                'Recovered Context Budget evidence has invalid max output tokens.',
            );
        }

        if (
            ! is_string($capacitySource)
            || $capacitySource === ''
        ) {
            throw new LogicException(
                'Recovered Context Budget evidence has an invalid capacity source.',
            );
        }

        if (
            ! is_int($capacitySourceVersion)
            || $capacitySourceVersion <= 0
        ) {
            throw new LogicException(
                'Recovered Context Budget evidence has an invalid capacity source version.',
            );
        }

        if (! is_bool($fallback)) {
            throw new LogicException(
                'Recovered Context Budget evidence has an invalid fallback flag.',
            );
        }

        return [
            'harness' => $harness,
            'model' => $model,
            'resolved_capacity_tokens' => $resolvedCapacity,
            'max_output_tokens' => $maxOutputTokens,
            'capacity_source' => $capacitySource,
            'capacity_source_version' => $capacitySourceVersion,
            'fallback' => $fallback,
        ];
    }

    /**
     * Record append-only Context Budget audit evidence.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function recordEvidence(
        AgentRun $run,
        array $evidence,
    ): void {
        $base = [
            'agent_run_id' => $run->id,
            'role' => (string) $run->getRawOriginal(
                'role',
            ),
            'harness' => $run->harness,
            'policy_version' => $evidence[
                    'policy_version'
                ] ?? null,
            'resolved_capacity_tokens' => $evidence[
                    'resolved_capacity_tokens'
                ] ?? null,
            'original_estimated_tokens' => $evidence[
                    'original_estimated_tokens'
                ] ?? null,
            'final_estimated_tokens' => $evidence[
                    'final_estimated_tokens'
                ] ?? null,
            'utilization_before' => $evidence[
                    'utilization_before'
                ] ?? null,
            'utilization_after' => $evidence[
                    'utilization_after'
                ] ?? null,
            'final_context_hash' => $evidence[
                    'final_context_hash'
                ] ?? null,
        ];

        $this->audit->record(
            'context_budget.evaluated',
            [
                ...$base,
                'decision' => $evidence[
                        'decision'
                    ] ?? null,
            ],
            $run->project,
            $run->task,
        );

        if (
            (
                $evidence[
                    'warning_reason'
                ] ?? null
            ) !== null
        ) {
            $this->audit->record(
                'context_budget.warning',
                [
                    ...$base,
                    'reason' => $evidence[
                            'warning_reason'
                        ],
                ],
                $run->project,
                $run->task,
            );
        }

        if (
            (
                $evidence[
                    'reductions'
                ] ?? []
            ) !== []
        ) {
            $this->audit->record(
                'context_budget.reduced',
                [
                    ...$base,
                    'reductions' => $evidence[
                            'reductions'
                        ],
                ],
                $run->project,
                $run->task,
            );
        }

        if (
            (
                $evidence[
                    'block_reason'
                ] ?? null
            ) !== null
        ) {
            $this->audit->record(
                'context_budget.blocked',
                [
                    ...$base,
                    'reason' => $evidence[
                            'block_reason'
                        ],
                ],
                $run->project,
                $run->task,
            );
        }
    }

    /**
     * Identify AIOS-managed context envelopes.
     */
    private function looksLikeManagedPrompt(
        string $prompt,
    ): bool {
        return str_contains(
            $prompt,
            "AIOS assembled context:\n",
        )
            || preg_match(
                '/\n\n\{(?:"context_schema_version"|"task":\{"context_schema_version")/',
                $prompt,
            ) === 1;
    }

    /**
     * Return a normalized failure without dispatching the provider.
     */
    private function failure(
        AgentHarnessIdentifier $identifier,
        string $message,
        string $failureType,
    ): NormalizedExecutionResult {
        return new NormalizedExecutionResult(
            exitCode: -1,
            output: '',
            errorOutput: $message,
            providerMetadata: [
                'provider' => $identifier->value,
                'failure_type' => $failureType,
            ],
        );
    }
}
