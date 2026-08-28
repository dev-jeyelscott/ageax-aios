<?php

namespace App\Models;

use App\OrchestrationRecommendationStatus;
use App\OrchestrationRecommendationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'project_id',
    'task_id',
    'recovery_incident_id',
    'agent_run_id',
    'recommendation_type',
    'schema_version',
    'evidence_hash',
    'confidence',
    'structured_recommendation',
    'status',
])]
/**
 * Immutable advisory recommendation evidence produced from one Orchestrator AgentRun.
 *
 * @property OrchestrationRecommendationType $recommendation_type
 * @property OrchestrationRecommendationStatus $status
 * @property int $schema_version
 * @property string $evidence_hash
 * @property string $confidence
 * @property array<string, mixed> $structured_recommendation
 * @property ?int $status_changed_by_user_id
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable $created_at
 */
class OrchestrationRecommendation extends Model
{
    public const UPDATED_AT = null;

    /**
     * Lifecycle metadata may change without rewriting historical recommendation evidence.
     *
     * @var list<string>
     */
    private const array MutableLifecycleAttributes = [
        'status',
        'status_changed_by_user_id',
        'status_changed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Enforce immutable recommendation evidence and one-way terminal lifecycle transitions.
     */
    protected static function booted(): void
    {
        static::creating(function (OrchestrationRecommendation $recommendation): void {
            $recommendation->assertInitialLifecycleStateIsValid();
        });

        static::updating(function (OrchestrationRecommendation $recommendation): void {
            foreach (array_keys($recommendation->getDirty()) as $attribute) {
                if (! in_array($attribute, self::MutableLifecycleAttributes, true)) {
                    throw new LogicException(
                        'Orchestration recommendation evidence is immutable.',
                    );
                }
            }

            $recommendation->assertLifecycleTransitionIsValid();
        });

        static::deleting(function (): void {
            throw new LogicException(
                'Orchestration recommendation evidence cannot be deleted directly.',
            );
        });
    }

    /**
     * Cast persisted recommendation evidence and lifecycle metadata to bounded domain types.
     */
    protected function casts(): array
    {
        return [
            'recommendation_type' => OrchestrationRecommendationType::class,
            'schema_version' => 'integer',
            'confidence' => 'decimal:4',
            'structured_recommendation' => 'array',
            'status' => OrchestrationRecommendationStatus::class,
            'status_changed_by_user_id' => 'integer',
            'status_changed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the optional project scope for this recommendation.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the optional task scope for this recommendation.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Return the optional recovery incident scope for this recommendation.
     *
     * @return BelongsTo<RecoveryIncident, $this>
     */
    public function recoveryIncident(): BelongsTo
    {
        return $this->belongsTo(RecoveryIncident::class);
    }

    /**
     * Return the immutable Orchestrator execution that produced the recommendation.
     *
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * Return the operator who made the terminal recommendation lifecycle decision.
     *
     * @return BelongsTo<User, $this>
     */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    /**
     * Ensure every newly persisted recommendation begins as active without operator lifecycle metadata.
     */
    private function assertInitialLifecycleStateIsValid(): void
    {
        $status = $this->getAttribute('status');

        if (
            ! $status instanceof OrchestrationRecommendationStatus
            || $status !== OrchestrationRecommendationStatus::Active
            || $this->getAttribute('status_changed_by_user_id') !== null
            || $this->getAttribute('status_changed_at') !== null
        ) {
            throw new LogicException(
                'New orchestration recommendations must begin in the active advisory state.',
            );
        }
    }

    /**
     * Allow exactly one active-to-terminal lifecycle decision with durable operator attribution.
     */
    private function assertLifecycleTransitionIsValid(): void
    {
        $previousStatusValue = $this->getRawOriginal('status');
        $previousStatus = is_string($previousStatusValue)
            ? OrchestrationRecommendationStatus::tryFrom($previousStatusValue)
            : null;
        $currentStatus = $this->getAttribute('status');

        if (
            $previousStatus !== OrchestrationRecommendationStatus::Active
            || ! $currentStatus instanceof OrchestrationRecommendationStatus
            || ! in_array(
                $currentStatus,
                [
                    OrchestrationRecommendationStatus::Dismissed,
                    OrchestrationRecommendationStatus::Superseded,
                ],
                true,
            )
        ) {
            throw new LogicException(
                'A finalized orchestration recommendation lifecycle state cannot be changed.',
            );
        }

        $operatorId = $this->getAttribute('status_changed_by_user_id');
        $changedAt = $this->getAttribute('status_changed_at');

        if (
            ! is_int($operatorId)
            || $operatorId < 1
            || ! $changedAt instanceof CarbonImmutable
        ) {
            throw new LogicException(
                'Recommendation lifecycle changes require durable operator attribution.',
            );
        }
    }
}
