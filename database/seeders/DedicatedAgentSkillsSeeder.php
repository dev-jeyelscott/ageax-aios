<?php

namespace Database\Seeders;

use App\Actions\ProvisionDedicatedAgentSkills;
use App\Models\Project;
use Illuminate\Database\Seeder;

class DedicatedAgentSkillsSeeder extends Seeder
{
    public function run(ProvisionDedicatedAgentSkills $provisionSkills): void
    {
        foreach (Project::query()->lazyById() as $project) {
            $provisionSkills->handle($project);
        }
    }
}
