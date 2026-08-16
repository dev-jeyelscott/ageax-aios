<?php

namespace App\Actions;

use App\Models\Agent;
use App\Models\AgentSkill;
use App\Models\Skill;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

class ReorderAgentSkills
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<int>  $orderedSkillIds
     */
    public function handle(Agent $agent, array $orderedSkillIds): void
    {
        DB::transaction(function () use ($agent, $orderedSkillIds): void {
            $lockedAgent = Agent::query()->whereKey($agent)->lockForUpdate()->firstOrFail();

            $assignments = AgentSkill::query()
                ->where('agent_id', $lockedAgent->id)
                ->get()
                ->keyBy('skill_id');

            if ($assignments->count() !== count($orderedSkillIds) || $assignments->keys()->diff($orderedSkillIds)->isNotEmpty()) {
                throw new LogicException("Reordering must include exactly the agent's currently assigned skills.");
            }

            $skills = Skill::query()
                ->where('project_id', $lockedAgent->project_id)
                ->whereIn('id', $orderedSkillIds)
                ->get()
                ->keyBy('id');

            $orderedSkills = [];

            foreach ($orderedSkillIds as $index => $skillId) {
                $assignment = $assignments->get($skillId);
                $skill = $skills->get($skillId);

                if (! $assignment instanceof AgentSkill || ! $skill instanceof Skill) {
                    throw new LogicException('An assigned skill could not be resolved for reordering.');
                }

                $position = $index + 1;

                $assignment->update(['position' => $position]);

                $orderedSkills[] = [
                    'skill_id' => $skill->id,
                    'skill_version' => $skill->version,
                    'position' => $position,
                ];
            }

            $project = $lockedAgent->project()->firstOrFail();

            $this->audit->record('skill.reordered', [
                'project_id' => $project->id,
                'agent_id' => $lockedAgent->id,
                'agent_configuration_version' => $lockedAgent->configuration_version,
                'skills' => $orderedSkills,
            ], $project);
        }, attempts: 3);
    }
}
