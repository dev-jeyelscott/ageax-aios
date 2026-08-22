<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_planning_escalation_id', 'agent_run_id', 'number', 'status', 'proposal', 'claimed_at', 'finished_at'])]
/** @property string $status */
class TaskPlanningRevisionAttempt extends Model
{
    protected function casts(): array
    {
        return ['proposal' => 'array', 'claimed_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<TaskPlanningEscalation, $this> */
    public function escalation(): BelongsTo
    {
        return $this->belongsTo(TaskPlanningEscalation::class, 'task_planning_escalation_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
