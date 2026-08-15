<?php

namespace App\Actions;

use App\Models\Agent;
use App\Models\AgentSkill;
use Illuminate\Support\Facades\DB;
use LogicException;

class ReorderAgentSkills
{
    /**
     * @param  list<int>  $orderedSkillIds
     */
    public function handle(Agent $agent, array $orderedSkillIds): void
    {
        DB::transaction(function () use ($agent, $orderedSkillIds): void {
            $lockedAgent = Agent::query()->whereKey($agent)->lockForUpdate()->firstOrFail();

            $assignments = AgentSkill::query()->where('agent_id', $lockedAgent->id)->get()->keyBy('skill_id');

            if ($assignments->count() !== count($orderedSkillIds) || $assignments->keys()->diff($orderedSkillIds)->isNotEmpty()) {
                throw new LogicException("Reordering must include exactly the agent's currently assigned skills.");
            }

            foreach ($orderedSkillIds as $index => $skillId) {
                $assignments[$skillId]->update(['position' => $index + 1]);
            }
        }, attempts: 3);
    }
}
