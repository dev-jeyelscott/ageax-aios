<?php

namespace App\Services;

use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;

class WorkerHeartbeat
{
    public function beat(Project $project, AgentRole $role, string $status = 'working'): AgentWorker
    {
        $worker = AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->firstOrFail();
        $worker->update(['status' => $status, 'last_heartbeat_at' => now(), 'started_at' => $worker->started_at ?? now()]);

        return $worker->refresh();
    }
}
