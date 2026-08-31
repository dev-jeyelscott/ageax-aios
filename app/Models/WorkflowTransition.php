<?php

namespace App\Models;

use App\Exceptions\InvalidWorkflowMutation;
use Database\Factories\WorkflowTransitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workflow_definition_id', 'from_step_id', 'to_step_id'])]
class WorkflowTransition extends Model
{
    /** @use HasFactory<WorkflowTransitionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $transition): void {
            throw new InvalidWorkflowMutation('Workflow transitions are immutable once persisted; create a new workflow definition version instead.');
        });
    }

    /**
     * Return the workflow definition version that owns this transition.
     *
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function workflowDefinition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }

    /**
     * Return the step this transition originates from.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    /**
     * Return the step this transition leads to.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function toStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }
}
