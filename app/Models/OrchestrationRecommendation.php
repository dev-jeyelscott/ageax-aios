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
 * @property CarbonImmutable $created_at
 */
class OrchestrationRecommendation extends Model
{
    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Prevent recommendation evidence from being rewritten or deleted through Eloquent.
     */
    protected static function booted(): void
    {
        static::updating(function (OrchestrationRecommendation $recommendation): void {
            throw new LogicException('Orchestration recommendation evidence is immutable.');
        });

        static::deleting(function (OrchestrationRecommendation $recommendation): void {
            throw new LogicException('Orchestration recommendation evidence cannot be deleted directly.');
        });
    }

    /**
     * Cast persisted recommendation evidence to bounded domain types.
     */
    protected function casts(): array
    {
        return [
            'recommendation_type' => OrchestrationRecommendationType::class,
            'schema_version' => 'integer',
            'confidence' => 'decimal:4',
            'structured_recommendation' => 'array',
            'status' => OrchestrationRecommendationStatus::class,
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
}
