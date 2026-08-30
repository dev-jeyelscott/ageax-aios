<?php

namespace App\Models;

use Database\Factories\GoalRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'feature_spec_id', 'task_id', 'project_manager_agent_id', 'backend_engineer_agent_id', 'reviewer_agent_id', 'project_manager_agent_run_id', 'goal_text', 'contract', 'pm_output', 'configuration_snapshot', 'native_definition_hash', 'harness', 'model', 'approval_mode', 'status', 'version', 'context_hash', 'approved_at', 'completed_at'])]
class GoalRun extends Model
{
    /** @use HasFactory<GoalRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['contract' => 'array', 'pm_output' => 'array', 'configuration_snapshot' => 'array', 'approved_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<FeatureSpec, $this> */
    public function featureSpec(): BelongsTo
    {
        return $this->belongsTo(FeatureSpec::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return HasMany<GoalSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(GoalSession::class);
    }
}
