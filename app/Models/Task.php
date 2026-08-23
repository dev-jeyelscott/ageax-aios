<?php

namespace App\Models;

use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['project_id', 'phase_id', 'key', 'position', 'title', 'objective', 'work_type', 'complexity', 'acceptance_criteria', 'scope', 'constraints', 'relevant_paths', 'verification_commands', 'implementation_prompt', 'context_capsule', 'status', 'claimed_at', 'completed_at'])]
/**
 * @property TaskStatus $status
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

    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'work_type' => TaskWorkType::class, 'complexity' => TaskComplexity::class, 'acceptance_criteria' => 'array', 'scope' => 'array', 'constraints' => 'array', 'relevant_paths' => 'array', 'verification_commands' => 'array', 'context_capsule' => 'array', 'claimed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Phase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /** @return HasMany<TaskAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(TaskAttempt::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /** @return HasMany<TaskOperatorMessage, $this> */
    public function operatorMessages(): HasMany
    {
        return $this->hasMany(TaskOperatorMessage::class);
    }

    /** @return HasMany<RecoveryIncident, $this> */
    public function recoveryIncidents(): HasMany
    {
        return $this->hasMany(RecoveryIncident::class);
    }

    /** @return BelongsToMany<Task, $this> */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    /** @return BelongsToMany<Task, $this> */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'depends_on_task_id', 'task_id');
    }

    /** @return HasOne<Ticket, $this> */
    public function originTicket(): HasOne
    {
        return $this->hasOne(Ticket::class, 'converted_task_id');
    }

    /** @return HasMany<TaskPlanningEscalation, $this> */
    public function planningEscalations(): HasMany
    {
        return $this->hasMany(TaskPlanningEscalation::class);
    }
}
