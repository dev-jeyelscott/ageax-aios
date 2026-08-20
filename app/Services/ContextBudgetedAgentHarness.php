<?php

namespace App\Services;

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
 * Normal PM/Coder/Reviewer/Ticket-triage execution creates the durable AgentRun before the
 * provider harness is invoked, so those paths always pass through Context Budget evaluation.
 * Raw harness calls without a matching AgentRun remain a narrow provider-boundary compatibility
 * path for harness capability/adapter verification and do not masquerade as AIOS workflow runs.
 */
final readonly class ContextBudgetedAgentHarness
{
    public function __construct(
        private ContextBudgetGuard $guard,
        private AuditLogger $audit,
    ) {}

    /**
     * @param Closure(string, ?Closure, ?Closure): NormalizedExecutionResult $provider
     */
    public function execute(
        AgentHarness $harness,
        Project $project,
        Agent $agent,
        string $prompt,
        Closure $provider,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
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
            );
        }

        try {
            $context = $this->guard->contextFromPrompt(
                $prompt,
            );

            $capacity = $harness
                ->capabilities()
                ->resolveContextCapacity(
                    $agent,
                    $harness->identifier(),
                );

            $recoverySource = $this->recoverySource(
                $run,
            );

            $persistedPolicy = null;

            if ($recoverySource !== null) {
                $snapshot = $this->arrayAttribute(
                    $recoverySource,
                    'context_budget_snapshot',
                );

                if ($snapshot === null) {
                    throw new LogicException(
                        'Recovered Context Budget evidence is missing.',
                    );
                }

                $capacity = $this->capacityFromSnapshot(
                    $snapshot,
                    $harness->identifier(),
                );

                $persistedPolicy = $snapshot;
            }

            $decision = $this->guard->evaluate(
                $this->role($run),
                $prompt,
                $context,
                $capacity,
                $persistedPolicy,
            );
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
                'Context Budget preflight failed safely: '.$throwable->getMessage(),
                'context_budget_preflight_failed',
            );
        }

        $evidence = [
            ...$decision->evidence,
            'recovery_snapshot_reused' =>
                $recoverySource !== null,
            'recovery_snapshot_source_run_id' =>
                $recoverySource?->id,
        ];

        $finalContext = $decision->context;

        $run->update([
            'prompt_hash' =>
                $evidence['final_prompt_hash'],
            'configuration_snapshot' =>
                $finalContext?->configurationSnapshot(),
            'context_schema_version' =>
                $finalContext?->contextSchemaVersion,
            'context_cost_estimate' =>
                $finalContext?->contextCostEstimate,
            'context_cost_schema_version' =>
                $finalContext?->contextCostSchemaVersion,
            'context_budget_snapshot' =>
                $evidence,
            'context_budget_schema_version' =>
                ContextBudgetPolicy::SchemaVersion,
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

        return $provider(
            $decision->prompt,
            $onOutput,
            $onHeartbeat,
        );
    }

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

        $candidate = AgentRun::query()
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
                $run->getRawOriginal('role'),
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
            $candidate->getRawOriginal('status')
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

    private function recoverySourceAttemptNumber(
        AgentRun $run,
    ): ?int {
        $role = $this->role($run);

        if ($role === AgentRole::Reviewer) {
            return (int) $run->attempt_number;
        }

        if ($role !== AgentRole::Coder) {
            return null;
        }

        $attempt = TaskAttempt::query()
            ->where(
                'task_id',
                $run->task_id,
            )
            ->where(
                'number',
                $run->attempt_number,
            )
            ->first();

        $validationResults =
            $attempt?->validation_results;

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
            ($repositoryPreflight['mode'] ?? null)
                !== 'recovery'
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

    private function role(
        AgentRun $run,
    ): AgentRole {
        return AgentRole::from(
            (string) $run->getRawOriginal('role'),
        );
    }

    /**
     * Read an Eloquent JSON-cast attribute from its serialized model
     * representation so static analysis receives the same casted value
     * that application code receives at runtime.
     *
     * @return array<string, mixed>|null
     */
    private function arrayAttribute(
        AgentRun $run,
        string $attribute,
    ): ?array {
        $attributes = $run->toArray();
        $value = $attributes[$attribute] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new LogicException(
                sprintf(
                    'AgentRun %s must resolve to an array.',
                    $attribute,
                ),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{
     *     harness: string,
     *     model: string|null,
     *     resolved_capacity_tokens: int,
     *     max_output_tokens: int|null,
     *     capacity_source: string,
     *     capacity_source_version: int,
     *     fallback: bool
     * }
     */
    private function capacityFromSnapshot(
        array $snapshot,
        AgentHarnessIdentifier $identifier,
    ): array {
        $harness =
            $snapshot['harness']
                ?? $identifier->value;

        $model =
            $snapshot['model']
                ?? null;

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
            'resolved_capacity_tokens' =>
                $resolvedCapacity,
            'max_output_tokens' =>
                $maxOutputTokens,
            'capacity_source' =>
                $capacitySource,
            'capacity_source_version' =>
                $capacitySourceVersion,
            'fallback' =>
                $fallback,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function recordEvidence(
        AgentRun $run,
        array $evidence,
    ): void {
        $base = [
            'agent_run_id' => $run->id,
            'role' =>
                (string) $run->getRawOriginal(
                    'role',
                ),
            'harness' => $run->harness,
            'policy_version' =>
                $evidence[
                    'policy_version'
                ] ?? null,
            'resolved_capacity_tokens' =>
                $evidence[
                    'resolved_capacity_tokens'
                ] ?? null,
            'original_estimated_tokens' =>
                $evidence[
                    'original_estimated_tokens'
                ] ?? null,
            'final_estimated_tokens' =>
                $evidence[
                    'final_estimated_tokens'
                ] ?? null,
            'utilization_before' =>
                $evidence[
                    'utilization_before'
                ] ?? null,
            'utilization_after' =>
                $evidence[
                    'utilization_after'
                ] ?? null,
            'final_context_hash' =>
                $evidence[
                    'final_context_hash'
                ] ?? null,
        ];

        $this->audit->record(
            'context_budget.evaluated',
            [
                ...$base,
                'decision' =>
                    $evidence[
                        'decision'
                    ] ?? null,
            ],
            $run->project,
            $run->task,
        );

        if (
            ($evidence['warning_reason'] ?? null)
                !== null
        ) {
            $this->audit->record(
                'context_budget.warning',
                [
                    ...$base,
                    'reason' =>
                        $evidence[
                            'warning_reason'
                        ],
                ],
                $run->project,
                $run->task,
            );
        }

        if (
            ($evidence['reductions'] ?? [])
                !== []
        ) {
            $this->audit->record(
                'context_budget.reduced',
                [
                    ...$base,
                    'reductions' =>
                        $evidence[
                            'reductions'
                        ],
                ],
                $run->project,
                $run->task,
            );
        }

        if (
            ($evidence['block_reason'] ?? null)
                !== null
        ) {
            $this->audit->record(
                'context_budget.blocked',
                [
                    ...$base,
                    'reason' =>
                        $evidence[
                            'block_reason'
                        ],
                ],
                $run->project,
                $run->task,
            );
        }
    }

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
                'provider' =>
                    $identifier->value,
                'failure_type' =>
                    $failureType,
            ],
        );
    }
}
