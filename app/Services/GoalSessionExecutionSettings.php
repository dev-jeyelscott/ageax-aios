<?php

namespace App\Services;

use App\AgentRole;
use App\Models\GoalRun;

class GoalSessionExecutionSettings
{
    /** @return array<string, mixed> */
    public function for(GoalRun $goalRun, AgentRole $role): array
    {
        $session = $goalRun->sessions()->where('role', $role)->first();

        if ($session === null) {
            return [];
        }

        return filled($session->provider_session_id)
            ? ['provider_session_id' => $session->provider_session_id]
            : ['persist_provider_session' => true];
    }
}
