<?php

namespace App\Actions;

use App\AgentHarness;
use App\AgentRole;
use App\Models\Project;
use App\Services\AuditLogger;

class ProvisionDefaultProjectAgents
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array<string, string> role value => default agent name */
    public static function defaultNames(): array
    {
        return [
            AgentRole::ProjectManager->value => 'Project Manager',
            AgentRole::Coder->value => 'Coder',
            AgentRole::Reviewer->value => 'Reviewer',
        ];
    }

    public function handle(Project $project): void
    {
        foreach ($this->defaults() as $definition) {
            $agent = $project->agents()->firstOrCreate(
                ['name' => $definition['name']],
                [
                    'role' => $definition['role'],
                    'harness' => AgentHarness::Codex,
                    'enabled' => true,
                ],
            );

            if (! $agent->wasRecentlyCreated) {
                continue;
            }

            $this->audit->record('agent.created', [
                'project_id' => $project->id,
                'agent_id' => $agent->id,
                'configuration_version' => $agent->configuration_version,
                'role' => $agent->role->value,
                'harness' => $agent->harness->value,
            ], $project);
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
