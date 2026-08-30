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
            AgentRole::BackendEngineer->value => 'Backend Engineer',
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
                    ...$this->nativeDefinition($definition['role']),
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
                'role' => (string) $agent->getRawOriginal('role'),
                'harness' => (string) $agent->getRawOriginal('harness'),
            ], $project);
        }
    }

    /** @return list<array{name: string, role: AgentRole}> */
    private function defaults(): array
    {
        return [
            ['name' => 'Project Manager', 'role' => AgentRole::ProjectManager],
            ['name' => 'Coder', 'role' => AgentRole::Coder],
            ['name' => 'Backend Engineer', 'role' => AgentRole::BackendEngineer],
            ['name' => 'Reviewer', 'role' => AgentRole::Reviewer],
        ];
    }

    /** @return array{provider_definition_path:?string, provider_definition_hash:?string, provider_definition_version:?string} */
    private function nativeDefinition(AgentRole $role): array
    {
        $name = match ($role) {
            AgentRole::ProjectManager => 'project-manager-goal',
            AgentRole::BackendEngineer => 'backend-engineer-goal',
            AgentRole::Reviewer => 'reviewer-goal',
            default => null,
        };

        if ($name === null) {
            return ['provider_definition_path' => null, 'provider_definition_hash' => null, 'provider_definition_version' => null];
        }

        $path = '.codex/agents/'.$name.'.md';
        $absolutePath = base_path($path);

        return ['provider_definition_path' => $path, 'provider_definition_hash' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null, 'provider_definition_version' => 'phase-14-v1'];
    }
}
