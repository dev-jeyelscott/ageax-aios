<?php

namespace App\Actions;

use App\AgentHarness;
use App\AgentRole;
use App\Models\Project;

class ProvisionDefaultProjectAgents
{
    public function handle(Project $project): void
    {
        foreach ($this->defaults() as $definition) {
            $project->agents()->firstOrCreate(
                ['name' => $definition['name']],
                [
                    'role' => $definition['role'],
                    'harness' => AgentHarness::Codex,
                    'enabled' => true,
                ],
            );
        }
    }

    /** @return list<array{name: string, role: AgentRole}> */
    private function defaults(): array
    {
        return [
            ['name' => 'Project Manager', 'role' => AgentRole::ProjectManager],
            ['name' => 'Coder', 'role' => AgentRole::Coder],
            ['name' => 'Reviewer', 'role' => AgentRole::Reviewer],
        ];
    }
}
