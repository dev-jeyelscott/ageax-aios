<?php

namespace App\Models;

use App\Exceptions\InvalidWorkflowMutation;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['project_id', 'knowledge_improvement_candidate_id', 'phase_id', 'workflow_definition_id', 'coder_worker_id', 'coder_worker_lease_id', 'key', 'position', 'title', 'objective', 'work_type', 'complexity', 'acceptance_criteria', 'scope', 'constraints', 'relevant_paths', 'verification_commands', 'implementation_prompt', 'context_capsule', 'stewardship_provenance', 'status', 'is_cleared', 'claimed_at', 'completed_at'])]
/**
 * @property TaskStatus $status
 * @property bool $is_cleared
 * @property ?int $coder_worker_id
 * @property ?string $coder_worker_lease_id
 * @property ?TaskWorkType $work_type
 * @property ?TaskComplexity $complexity
 * @property array<int, string> $acceptance_criteria
 * @property array<int, string>|null $scope
 * @property array<int, string>|null $constraints
 * @property array<int, string>|null $relevant_paths
 * @property array<int, string>|null $verification_commands
 * @property ?CarbonImmutable $completed_at
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $attributes = [
        'is_cleared' => false,
    ];

    protected static function booted(): void
    {
        static::updating(function (self $task): void {
            if ($task->isDirty('workflow_definition_id') && $task->getRawOriginal('workflow_definition_id') !== null) {
                throw new InvalidWorkflowMutation('The workflow definition version bound to a Task is immutable and cannot be silently rewritten.');
            }
        });
    }

    /**
     * Define the durable attribute casts used by Tasks.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'is_cleared' => 'boolean', 'work_type' => TaskWorkType::class, 'complexity' => TaskComplexity::class, 'acceptance_criteria' => 'array', 'scope' => 'array', 'constraints' => 'array', 'relevant_paths' => 'array', 'verification_commands' => 'array', 'context_capsule' => 'array', 'stewardship_provenance' => 'array', 'claimed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /**
     * Limit Task queries to durable records that have not been cleared.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notCleared(Builder $query): void
    {
        $query->where('is_cleared', false);
    }

    /**
     * Return the Project that owns this Task.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the optional Phase containing this Task.
     *
     * @return BelongsTo<Phase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /**
     * Return the immutable workflow definition version bound to this Task at creation, when selected.
     *
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function workflowDefinition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }

    /**
     * Return the Coder worker that currently owns this Task while it is coding or validating.
     *
     * @return BelongsTo<AgentWorker, $this>
     */
    public function coderWorker(): BelongsTo
    {
        return $this->belongsTo(AgentWorker::class, 'coder_worker_id');
    }

    /**
     * Return execution attempts recorded for this Task.
     *
     * @return HasMany<TaskAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(TaskAttempt::class);
    }

    /**
     * Return Reviews recorded for this Task.
     *
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Return AgentRuns associated with this Task.
     *
     * @return HasMany<AgentRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /**
     * Return durable typed Agent handoff evidence scoped to this Task.
     *
     * @return HasMany<AgentHandoff, $this>
     */
    public function handoffs(): HasMany
    {
        return $this->hasMany(AgentHandoff::class);
    }

    /**
     * Return durable audit events associated with this Task.
     *
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * Return operator messages associated with this Task.
     *
     * @return HasMany<TaskOperatorMessage, $this>
     */
    public function operatorMessages(): HasMany
    {
        return $this->hasMany(TaskOperatorMessage::class);
    }

    /**
     * Return recovery incidents associated with this Task.
     *
     * @return HasMany<RecoveryIncident, $this>
     */
    public function recoveryIncidents(): HasMany
    {
        return $this->hasMany(RecoveryIncident::class);
    }

    /**
     * Return Tasks that must be satisfied before this Task can proceed.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    /**
     * Return Tasks that depend on this Task.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'depends_on_task_id', 'task_id');
    }

    /**
     * Return the Ticket that was converted into this Task, when applicable.
     *
     * @return HasOne<Ticket, $this>
     */
    public function originTicket(): HasOne
    {
        return $this->hasOne(Ticket::class, 'converted_task_id');
    }

    /**
     * Return planning escalations associated with this Task.
     *
     * @return HasMany<TaskPlanningEscalation, $this>
     */
    public function planningEscalations(): HasMany
    {
        return $this->hasMany(TaskPlanningEscalation::class);
    }
}
