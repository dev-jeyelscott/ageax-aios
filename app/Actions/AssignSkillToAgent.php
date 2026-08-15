<?php

namespace App\Actions;

use App\Models\Agent;
use App\Models\AgentSkill;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;
use LogicException;

class AssignSkillToAgent
{
    public function handle(Agent $agent, Skill $skill, ?int $position = null): AgentSkill
    {
        return DB::transaction(function () use ($agent, $skill, $position): AgentSkill {
            $lockedAgent = Agent::query()->whereKey($agent)->lockForUpdate()->firstOrFail();
            $lockedSkill = Skill::query()->whereKey($skill)->lockForUpdate()->firstOrFail();

            if ((int) $lockedAgent->project_id !== (int) $lockedSkill->project_id) {
                throw new LogicException('Skill must belong to the same project as the agent.');
            }

            $alreadyAssigned = AgentSkill::query()
                ->where('agent_id', $lockedAgent->id)
                ->where('skill_id', $lockedSkill->id)
                ->exists();

            if ($alreadyAssigned) {
                throw new LogicException('This skill is already assigned to the agent.');
            }

            $nextPosition = $position ?? ((int) AgentSkill::query()->where('agent_id', $lockedAgent->id)->max('position')) + 1;

            return AgentSkill::query()->create([
                'agent_id' => $lockedAgent->id,
                'skill_id' => $lockedSkill->id,
                'position' => $nextPosition,
            ]);
        }, attempts: 3);
    }
}
