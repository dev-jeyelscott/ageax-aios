<?php

namespace App\Models;

use App\ProjectReconciliationStatus;
use App\ProjectReconciliationTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectReconciliationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'requested_by_user_id', 'agent_run_id', 'trigger', 'status', 'baseline_sha', 'evaluated_head_sha', 'snapshot_hash', 'working_tree_dirty', 'result', 'mechanical_result', 'failure_reason', 'started_at', 'finished_at'])]
/**
 * @property ProjectReconciliationTrigger $trigger
 * @property ProjectReconciliationStatus $status
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
class ProjectReconciliationRun extends Model
{
    /** @use HasFactory<ProjectReconciliationRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['trigger' => ProjectReconciliationTrigger::class, 'status' => ProjectReconciliationStatus::class, 'working_tree_dirty' => 'boolean', 'result' => 'array', 'mechanical_result' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
