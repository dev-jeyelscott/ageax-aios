<?php

namespace App\Services;

use App\AgentRole;
use App\Models\AgentRun;
use App\Models\GoalRun;

class GoalSessionRecorder
{
    public function recordLatest(GoalRun $goalRun, AgentRole $role): void
    {
        $run = AgentRun::query()->whereBelongsTo($goalRun->task)->where('role', $role)->latest('id')->first();
        if ($run === null) {
            return;
        }
        $goalRun->sessions()->where('role', $role)->update(['provider_session_id' => $run->external_run_id ?? $run->codex_run_id, 'last_used_at' => now(), 'runtime_metadata' => ['agent_run_id' => $run->id]]);
    }
}
