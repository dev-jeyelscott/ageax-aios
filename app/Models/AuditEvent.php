<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['project_id', 'task_id', 'event_type', 'payload', 'occurred_at'])]
/**
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $occurred_at
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit events are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit events are append-only.');
        });
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
