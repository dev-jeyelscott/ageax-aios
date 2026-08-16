<?php

namespace App\Actions;

use App\Models\Agent;
use App\Models\AgentSkill;
use App\Models\Skill;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

class AssignSkillToAgent
{
    public function __construct(private AuditLogger $audit) {}

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

            $assignment = AgentSkill::query()->create([
                'agent_id' => $lockedAgent->id,
                'skill_id' => $lockedSkill->id,
                'position' => $nextPosition,
            ]);

            $project = $lockedAgent->project()->firstOrFail();

            $this->audit->record('skill.assigned', [
                'project_id' => $project->id,
                'agent_id' => $lockedAgent->id,
                'agent_configuration_version' => $lockedAgent->configuration_version,
                'skill_id' => $lockedSkill->id,
                'skill_version' => $lockedSkill->version,
                'position' => $assignment->position,
            ], $project);

            return $assignment;
        }, attempts: 3);
    }
}
