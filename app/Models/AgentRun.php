<?php

namespace App\Models;

use App\AgentRole;
use App\AgentRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'task_id', 'agent_worker_id', 'recovery_incident_id', 'agent_id', 'worker_instance_id', 'worker_lease_id', 'role', 'harness', 'status', 'attempt_number', 'codex_run_id', 'external_run_id', 'prompt_hash', 'result', 'configuration_snapshot', 'context_schema_version', 'context_cost_estimate', 'context_cost_schema_version', 'context_budget_snapshot', 'context_budget_schema_version', 'commands', 'file_modifications', 'token_usage', 'log_path', 'live_output', 'exit_code', 'started_at', 'finished_at'])]
/**
 * @property AgentRole $role
 * @property AgentRunStatus $status
 * @property ?int $agent_id
 * @property ?string $harness
 * @property ?array<string, mixed> $configuration_snapshot
 * @property ?int $context_schema_version
 * @property ?array<string, mixed> $context_cost_estimate
 * @property ?int $context_cost_schema_version
 * @property ?array<string, mixed> $context_budget_snapshot
 * @property ?int $context_budget_schema_version
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['role' => AgentRole::class, 'status' => AgentRunStatus::class, 'result' => 'array', 'configuration_snapshot' => 'array', 'context_cost_estimate' => 'array', 'context_budget_snapshot' => 'array', 'commands' => 'array', 'file_modifications' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<AgentWorker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(AgentWorker::class, 'agent_worker_id');
    }

    /** @return BelongsTo<RecoveryIncident, $this> */
    public function recoveryIncident(): BelongsTo
    {
        return $this->belongsTo(RecoveryIncident::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * A run predates immutable configuration snapshots (P2-012) when it has none persisted.
     */
    public function isLegacyRun(): bool
    {
        return $this->context_schema_version === null;
    }
}
