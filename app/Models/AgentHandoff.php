<?php

namespace App\Models;

use App\AgentHandoffStatus;
use App\AgentHandoffType;
use App\AgentRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'project_id',
    'task_id',
    'from_agent_run_id',
    'from_role',
    'to_role',
    'handoff_type',
    'schema_version',
    'payload',
    'content_hash',
    'status',
    'consumed_at',
])]
/**
 * Durable AIOS-owned evidence transferred between fresh Agent executions.
 *
 * @property AgentRole $from_role
 * @property AgentRole $to_role
 * @property AgentHandoffType $handoff_type
 * @property AgentHandoffStatus $status
 * @property int $schema_version
 * @property array<string, mixed> $payload
 * @property string $content_hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $consumed_at
 */
class AgentHandoff extends Model
{
    public const UPDATED_AT = null;

    /**
     * Evidence identity must remain immutable after persistence.
     *
     * @var list<string>
     */
    private const array ImmutableEvidenceFields = [
        'project_id',
        'task_id',
        'from_agent_run_id',
        'from_role',
        'to_role',
        'handoff_type',
        'schema_version',
        'payload',
        'content_hash',
        'created_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Protect durable handoff evidence while leaving future consumption state mutable.
     */
    protected static function booted(): void
    {
        static::updating(function (AgentHandoff $handoff): void {
            foreach (self::ImmutableEvidenceFields as $attribute) {
                if ($handoff->isDirty($attribute)) {
                    throw new LogicException(
                        "Agent handoff evidence field [{$attribute}] is immutable.",
                    );
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException(
                'Agent handoff evidence cannot be deleted directly.',
            );
        });
    }

    /**
     * Cast persisted handoff evidence to bounded domain types.
     */
    protected function casts(): array
    {
        return [
            'from_role' => AgentRole::class,
            'to_role' => AgentRole::class,
            'handoff_type' => AgentHandoffType::class,
            'schema_version' => 'integer',
            'payload' => 'array',
            'status' => AgentHandoffStatus::class,
            'created_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the authoritative project scope of this handoff.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the optional Task scope proven by the source AgentRun.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Return the AgentRun that produced this durable evidence.
     *
     * @return BelongsTo<AgentRun, $this>
     */
    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'from_agent_run_id');
    }
}
