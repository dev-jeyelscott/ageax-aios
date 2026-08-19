<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use Closure;
use LogicException;

/**
 * AIOS-owned dispatch gate. Provider harnesses remain unaware of budgeting/reduction;
 * this decorator only applies the centralized ContextBudgetGuard immediately before
 * delegating to the selected provider implementation.
 */
final readonly class ContextBudgetedAgentHarness implements AgentHarness
{
    public function __construct(
        private AgentHarness $inner,
        private ContextBudgetGuard $guard,
        private AuditLogger $audit,
    ) {}

    public function identifier(): AgentHarnessIdentifier
    {
        return $this->inner->identifier();
    }

    public function capabilities(): HarnessCapabilities
    {
        return $this->inner->capabilities();
    }

    public function execute(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
    ): NormalizedExecutionResult {
        $run = $this->currentRun($project, $agent, $prompt);

        if ($run === null) {
            return $this->failure(
                'Context Budget preflight evidence cannot be attached because no matching running AgentRun exists.',
                'context_budget_run_missing',
            );
        }

        try {
            $context = $this->guard->contextFromPrompt($prompt);
            $capacity = $this->capabilities()->resolveContextCapacity(
                $agent,
                $this->identifier(),
            );
            $recoverySource = $this->recoverySource($run);
            $persistedPolicy = null;

            if ($recoverySource !== null) {
                $snapshot = $recoverySource->getAttribute('context_budget_snapshot');

                if (! is_array($snapshot)) {
                    throw new LogicException('Recovered Context Budget evidence is missing or malformed.');
                }

                $capacity = $this->capacityFromSnapshot($snapshot);
                $persistedPolicy = $snapshot;
            }

            $decision = $this->guard->evaluate(
                AgentRole::from((string) $run->getRawOriginal('role')),
                $prompt,
                $context,
                $capacity,
                $persistedPolicy,
            );
        } catch (\Throwable $throwable) {
            $this->audit->record('context_budget.blocked', [
                'agent_run_id' => $run->id,
                'reason' => 'context_budget_preflight_failed',
                'error' => $throwable->getMessage(),
            ], $project, $run->task);

            return $this->failure(
                'Context Budget preflight failed safely: '.$throwable->getMessage(),
                'context_budget_preflight_failed',
            );
        }

        $evidence = [
            ...$decision->evidence,
            'recovery_snapshot_reused' => $recoverySource !== null,
            'recovery_snapshot_source_run_id' => $recoverySource?->id,
        ];
        $finalContext = $decision->context;

        $run->update([
            'prompt_hash' => $evidence['final_prompt_hash'],
            'configuration_snapshot' => $finalContext?->configurationSnapshot(),
            'context_schema_version' => $finalContext?->contextSchemaVersion,
            'context_cost_estimate' => $finalContext?->contextCostEstimate,
            'context_cost_schema_version' => $finalContext?->contextCostSchemaVersion,
            'context_budget_snapshot' => $evidence,
            'context_budget_schema_version' => ContextBudgetPolicy::SchemaVersion,
        ]);

        $this->recordEvidence($run->refresh(), $evidence);

        if ($decision->blocked) {
            return $this->failure(
                'Context Budget blocked provider execution: '.($evidence['block_reason'] ?? 'hard ceiling reached.'),
                'context_budget_blocked',
            );
        }

        return $this->inner->execute(
            $project,
            $agent,
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
            ->where('status', AgentRunStatus::Running)
            ->where('prompt_hash', hash('sha256', $prompt))
            ->latest('id')
            ->first();
    }

    private function recoverySource(AgentRun $run): ?AgentRun
    {
        $configurationSnapshot = $run->getAttribute('configuration_snapshot');

        if ($run->task_id === null || ! is_array($configurationSnapshot)) {
            return null;
        }

        return AgentRun::query()
            ->where('project_id', $run->project_id)
            ->where('task_id', $run->task_id)
            ->where('role', $run->getRawOriginal('role'))
            ->where('id', '<', $run->id)
            ->where('status', AgentRunStatus::Interrupted)
            ->whereNotNull('context_budget_snapshot')
            ->latest('id')
            ->limit(10)
            ->get()
            ->first(function (AgentRun $candidate) use ($configurationSnapshot): bool {
                $candidateSnapshot = $candidate->getAttribute('configuration_snapshot');

                return is_array($candidateSnapshot)
                    && $candidateSnapshot === $configurationSnapshot;
            });
    }

    /**
     * @param  array<string, mixed>  $snapshot
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
    private function capacityFromSnapshot(array $snapshot): array
    {
        $harness = $snapshot['harness'] ?? $this->identifier()->value;
        $model = $snapshot['model'] ?? null;
        $resolvedCapacity = $snapshot['resolved_capacity_tokens'] ?? null;
        $maxOutputTokens = $snapshot['max_output_tokens'] ?? null;
        $capacitySource = $snapshot['capacity_source'] ?? null;
        $capacitySourceVersion = $snapshot['capacity_source_version'] ?? null;
        $fallback = $snapshot['capacity_fallback'] ?? false;

        if (! is_string($harness) || $harness === '') {
            throw new LogicException('Recovered Context Budget evidence has an invalid harness.');
        }

        if ($model !== null && ! is_string($model)) {
            throw new LogicException('Recovered Context Budget evidence has an invalid model.');
        }

        if (! is_int($resolvedCapacity) || $resolvedCapacity <= 0) {
            throw new LogicException('Recovered Context Budget evidence has an invalid resolved capacity.');
        }

        if ($maxOutputTokens !== null && (! is_int($maxOutputTokens) || $maxOutputTokens <= 0)) {
            throw new LogicException('Recovered Context Budget evidence has invalid max output tokens.');
        }

        if (! is_string($capacitySource) || $capacitySource === '') {
            throw new LogicException('Recovered Context Budget evidence has an invalid capacity source.');
        }

        if (! is_int($capacitySourceVersion) || $capacitySourceVersion <= 0) {
            throw new LogicException('Recovered Context Budget evidence has an invalid capacity source version.');
        }

        if (! is_bool($fallback)) {
            throw new LogicException('Recovered Context Budget evidence has an invalid fallback flag.');
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

    /** @param array<string, mixed> $evidence */
    private function recordEvidence(AgentRun $run, array $evidence): void
    {
        $base = [
            'agent_run_id' => $run->id,
            'role' => (string) $run->getRawOriginal('role'),
            'harness' => $run->harness,
            'policy_version' => $evidence['policy_version'] ?? null,
            'resolved_capacity_tokens' => $evidence['resolved_capacity_tokens'] ?? null,
            'original_estimated_tokens' => $evidence['original_estimated_tokens'] ?? null,
            'final_estimated_tokens' => $evidence['final_estimated_tokens'] ?? null,
            'utilization_before' => $evidence['utilization_before'] ?? null,
            'utilization_after' => $evidence['utilization_after'] ?? null,
            'final_context_hash' => $evidence['final_context_hash'] ?? null,
        ];

        $this->audit->record(
            'context_budget.evaluated',
            [...$base, 'decision' => $evidence['decision'] ?? null],
            $run->project,
            $run->task,
        );

        if (($evidence['warning_reason'] ?? null) !== null) {
            $this->audit->record(
                'context_budget.warning',
                [...$base, 'reason' => $evidence['warning_reason']],
                $run->project,
                $run->task,
            );
        }

        if (($evidence['reductions'] ?? []) !== []) {
            $this->audit->record(
                'context_budget.reduced',
                [...$base, 'reductions' => $evidence['reductions']],
                $run->project,
                $run->task,
            );
        }

        if (($evidence['block_reason'] ?? null) !== null) {
            $this->audit->record(
                'context_budget.blocked',
                [...$base, 'reason' => $evidence['block_reason']],
                $run->project,
                $run->task,
            );
        }
    }

    private function failure(
        string $message,
        string $failureType,
    ): NormalizedExecutionResult {
        return new NormalizedExecutionResult(
            exitCode: -1,
            output: '',
            errorOutput: $message,
            providerMetadata: [
                'provider' => $this->identifier()->value,
                'failure_type' => $failureType,
            ],
        );
    }
}
