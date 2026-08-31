<?php

namespace App\Models;

use App\Exceptions\InvalidWorkflowMutation;
use App\WorkflowStepKind;
use Database\Factories\WorkflowStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property WorkflowStepKind $kind
 * @property int $position
 */
#[Fillable(['workflow_definition_id', 'key', 'position', 'kind', 'label'])]
class WorkflowStep extends Model
{
    /** @use HasFactory<WorkflowStepFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $step): void {
            throw new InvalidWorkflowMutation('Workflow steps are immutable once persisted; create a new workflow definition version instead.');
        });
    }

    /**
     * Define the durable attribute casts used by workflow steps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => WorkflowStepKind::class, 'position' => 'integer'];
    }

    /**
     * Return the workflow definition version that owns this step.
     *
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function workflowDefinition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }
}
