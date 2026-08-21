<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['task_id', 'source_task_attempt_id', 'defect_type', 'fingerprint', 'failure_evidence', 'allowed_fields', 'status', 'resolved_at'])]
class TaskPlanningEscalation extends Model
{
    protected function casts(): array
    {
        return ['failure_evidence' => 'array', 'allowed_fields' => 'array', 'resolved_at' => 'immutable_datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function sourceAttempt(): BelongsTo
    {
        return $this->belongsTo(TaskAttempt::class, 'source_task_attempt_id');
    }

    public function revisionAttempts(): HasMany
    {
        return $this->hasMany(TaskPlanningRevisionAttempt::class);
    }
}
