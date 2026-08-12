<?php

namespace App\Models;

use App\ReviewStatus;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['task_id', 'task_attempt_id', 'status', 'summary', 'started_at', 'completed_at'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => ReviewStatus::class, 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TaskAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TaskAttempt::class, 'task_attempt_id');
    }

    /** @return HasMany<ReviewFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class);
    }
}
