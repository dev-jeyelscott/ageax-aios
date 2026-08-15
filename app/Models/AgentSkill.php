<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/**
 * @property int $agent_id
 * @property int $skill_id
 * @property int $position
 */
class AgentSkill extends Pivot
{
    protected $table = 'agent_skill';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::creating(function (AgentSkill $assignment): void {
            $agent = Agent::query()->findOrFail($assignment->agent_id);
            $skill = Skill::query()->findOrFail($assignment->skill_id);

            if ((int) $agent->project_id !== (int) $skill->project_id) {
                throw new LogicException('Skill must belong to the same project as the agent.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
