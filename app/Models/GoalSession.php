<?php

namespace App\Models;

use Database\Factories\GoalSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['goal_run_id', 'agent_id', 'role', 'harness', 'provider_session_id', 'status', 'runtime_metadata', 'last_used_at'])]
class GoalSession extends Model
{
    /** @use HasFactory<GoalSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['runtime_metadata' => 'array', 'last_used_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<GoalRun, $this> */
    public function goalRun(): BelongsTo
    {
        return $this->belongsTo(GoalRun::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
