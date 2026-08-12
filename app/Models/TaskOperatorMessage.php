<?php

namespace App\Models;

use App\AgentRole;
use Database\Factories\TaskOperatorMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'user_id', 'recipient_role', 'body', 'delivered_at'])]
class TaskOperatorMessage extends Model
{
    /** @use HasFactory<TaskOperatorMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['recipient_role' => AgentRole::class, 'delivered_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
