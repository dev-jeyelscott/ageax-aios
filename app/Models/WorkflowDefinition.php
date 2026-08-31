<?php

namespace App\Models;

use App\Exceptions\InvalidWorkflowMutation;
use App\WorkflowDefinitionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\WorkflowDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $key
 * @property int $version
 * @property WorkflowDefinitionStatus $status
 * @property ?CarbonImmutable $approved_at
 * @property ?CarbonImmutable $archived_at
 */
#[Fillable(['key', 'version', 'name', 'description', 'status', 'created_by_user_id', 'approved_by_user_id', 'approved_at', 'archived_at'])]
class WorkflowDefinition extends Model
{
    /** @use HasFactory<WorkflowDefinitionFactory> */
    use HasFactory;

    /** Fields whose content defines the immutable version and can never change after creation. */
    private const IMMUTABLE_FIELDS = ['key', 'version', 'name', 'description', 'created_by_user_id'];

    protected static function booted(): void
    {
        static::updating(function (self $definition): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($definition->isDirty($field)) {
                    throw new InvalidWorkflowMutation("The workflow definition field [{$field}] is immutable and cannot be rewritten.");
                }
            }
        });
    }

    /**
     * Define the durable attribute casts used by workflow definitions.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkflowDefinitionStatus::class,
            'version' => 'integer',
            'approved_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the User who created this workflow definition version.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Return the User who approved this workflow definition version, when applicable.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Return the declarative steps that belong to this workflow definition version.
     *
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    /**
     * Return the declarative transitions that belong to this workflow definition version.
     *
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    /**
     * Return Tasks bound to this exact workflow definition version.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
