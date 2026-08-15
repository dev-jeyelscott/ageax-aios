<?php

namespace App\Actions;

use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentWorker;
use Illuminate\Support\Facades\DB;
use LogicException;

class BindAgentWorker
{
    public function handle(AgentWorker $worker, Agent $agent): AgentWorker
    {
        return DB::transaction(function () use ($worker, $agent): AgentWorker {
            $lockedWorker = AgentWorker::query()->whereKey($worker)->lockForUpdate()->firstOrFail();
            $lockedAgent = Agent::query()->whereKey($agent)->lockForUpdate()->firstOrFail();

            if ((int) $lockedWorker->project_id !== (int) $lockedAgent->project_id) {
                throw new LogicException('Agent must belong to the same project as the worker.');
            }

            if ($lockedWorker->role !== $lockedAgent->role) {
                throw new LogicException('Agent role must match the worker role.');
            }

            if (! $lockedAgent->enabled) {
                throw new LogicException('Disabled agents cannot be bound to workflow workers.');
            }

            if ((int) $lockedWorker->agent_id === (int) $lockedAgent->id) {
                return $lockedWorker;
            }

            if ($this->hasActiveExecution($lockedWorker)) {
                throw new LogicException('A workflow worker with an active lease or run cannot be rebound.');
            }

            $lockedWorker->agent()->associate($lockedAgent);
            $lockedWorker->save();

            return $lockedWorker->refresh();
        }, attempts: 3);
    }

    private function hasActiveExecution(AgentWorker $worker): bool
    {
        if ($worker->lease_id !== null && AgentWorker::query()->whereKey($worker)->where('lease_expires_at', '>', now())->exists()) {
            return true;
        }

        return $worker->runs()->where('status', AgentRunStatus::Running)->exists();
    }
}
