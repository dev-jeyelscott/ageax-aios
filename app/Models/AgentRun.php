<?php

namespace App\Models;

use App\AgentRole;
use App\AgentRunStatus;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'task_id', 'agent_worker_id', 'worker_instance_id', 'worker_lease_id', 'role', 'status', 'attempt_number', 'codex_run_id', 'prompt_hash', 'result', 'commands', 'file_modifications', 'token_usage', 'log_path', 'live_output', 'exit_code', 'started_at', 'finished_at'])]
/**
 * @property AgentRole $role
 * @property AgentRunStatus $status
 */
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['role' => AgentRole::class, 'status' => AgentRunStatus::class, 'result' => 'array', 'commands' => 'array', 'file_modifications' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
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

    /** @return BelongsTo<AgentWorker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(AgentWorker::class, 'agent_worker_id');
    }
}
