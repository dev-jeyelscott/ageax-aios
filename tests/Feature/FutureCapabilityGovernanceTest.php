<?php

use App\Actions\BindAgentWorker;
use App\AgentHarness;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\TaskWorkflow;
use App\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set(
        'aios.obsidian_vault_path',
        storage_path('framework/testing/obsidian-future-governance-'.fake()->uuid()),
    );
});

/**
 * Create an isolated running project for future-capability governance tests.
 */
function futureGovernanceProject(string $name): Project
{
    $path = sys_get_temp_dir().'/ageax-future-governance-'.Str::uuid();
    File::ensureDirectoryExists($path);

    return Project::create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one queued task whose workflow authority must remain AIOS-owned.
 */
function futureGovernanceTask(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Future capability governance',
        'objective' => 'Prove future capability roles cannot bypass AIOS workflow authority.',
        'acceptance_criteria' => ['AIOS remains the durable workflow authority.'],
        'implementation_prompt' => 'Preserve the locked governance boundary.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/**
 * Assert that a governance document still contains all required authority clauses.
 *
 * @param  list<string>  $clauses
 */
function expectFutureGovernanceClauses(string $path, array $clauses): void
{
    $contents = (string) str(File::get(base_path($path)))->squish();

    foreach ($clauses as $clause) {
        expect($contents)->toContain((string) str($clause)->squish());
    }
}

test('phase four governance contracts keep future capabilities subordinate to AIOS authority', function (): void {
    expectFutureGovernanceClauses('MASTER-PROMPT.md', [
        'Phase 4+ Architectural Governance Contract',
        'No present or future Agent may own durable workflow state',
        'A future Global Orchestrator starts advisory-only.',
        'Knowledge Architect is proposal-first',
        'Voice is input/output only',
        'Automatic routing requires explicit operator policy and evidence',
    ]);

    expectFutureGovernanceClauses('AGENTS.md', [
        'Phase 4+ authority contract for all Agents',
        'Phase 4+ capability documentation does not itself activate new Agent roles',
        'Laravel/AIOS remains the sole owner of authorization',
        'No Agent may choose itself or another Agent for execution',
    ]);

    expectFutureGovernanceClauses('CLAUDE.md', [
        'Phase 4+ Claude Code capability boundaries',
        'Phase 4+ governance does not activate any new Claude Code authority.',
        '**Global Orchestrator** remains advisory.',
        '**Knowledge Architect** remains proposal-only.',
    ]);

    expectFutureGovernanceClauses('.ai/rules/actions.md', [
        'Phase 4+ Actions remain the only durable mutation boundary',
        'only an authorized AIOS-owned Action may validate and persist a durable mutation',
    ]);

    expectFutureGovernanceClauses('.ai/rules/services.md', [
        'Phase 4+ capability services remain subordinate to AIOS authority',
        'must not become a competing workflow/state system',
        'Repository integration remains serialized per repository and AIOS-owned',
    ]);

    expectFutureGovernanceClauses('.ai/rules/knowledge-improvements.md', [
        'Phase 4+ Knowledge Architect remains proposal-only',
        'must never directly mutate Skills',
    ]);
});

test('enum membership does not grant unapproved roles global Agent authority', function (): void {
    $approvedGlobalRoles = [
        AgentRole::RecoveryEngineer,
        AgentRole::Orchestrator,
    ];

    foreach (AgentRole::cases() as $role) {
        if (in_array($role, $approvedGlobalRoles, true)) {
            continue;
        }

        expect(fn () => Agent::query()->create([
            'name' => 'Unauthorized Global '.Str::headline($role->value),
            'role' => $role,
            'harness' => AgentHarness::Codex,
            'enabled' => true,
        ]))->toThrow(
            LogicException::class,
            'A global Agent role must be a supported AIOS system role.',
        );
    }
});

test('the persisted global system Agent authority is exactly Recovery Engineer and Orchestrator', function (): void {
    $roles = Agent::query()
        ->whereNull('project_id')
        ->get()
        ->map(fn (Agent $agent): string => $agent->role->value)
        ->sort()
        ->values()
        ->all();

    expect($roles)->toBe([
        AgentRole::Orchestrator->value,
        AgentRole::RecoveryEngineer->value,
    ]);
});

test('approved global Agent role identities cannot be mutated into an unapproved future role', function (): void {
    foreach ([AgentRole::RecoveryEngineer, AgentRole::Orchestrator] as $role) {
        $agent = Agent::query()
            ->whereNull('project_id')
            ->where('role', $role)
            ->sole();

        $agent->role = AgentRole::KnowledgeArchitect;

        expect(fn () => $agent->save())->toThrow(LogicException::class);
        expect($agent->fresh()?->role)->toBe($role);
    }
});

test('Orchestrator cannot be persisted as a project Agent', function (): void {
    $project = futureGovernanceProject('Orchestrator Project Scope');

    expect(fn () => Agent::query()->create([
        'project_id' => $project->id,
        'name' => 'Invalid Project Orchestrator',
        'role' => AgentRole::Orchestrator,
        'harness' => AgentHarness::Codex,
        'enabled' => true,
    ]))->toThrow(
        LogicException::class,
        'Agent role must be a supported AIOS workflow role.',
    );
});

test('non task roles cannot claim or transition queued Task work through TaskWorkflow', function (): void {
    $project = futureGovernanceProject('Future Task Authority');
    $task = futureGovernanceTask($project);
    $workflow = app(TaskWorkflow::class);

    foreach (AgentRole::cases() as $role) {
        if (in_array($role, [AgentRole::Coder, AgentRole::Reviewer], true)) {
            continue;
        }

        expect($workflow->claim($project, $role))->toBeNull();
        expect($task->refresh()->status)->toBe(TaskStatus::Queued);
    }
});

test('current worker authority remains one durable role lane per project', function (): void {
    $project = futureGovernanceProject('Future Worker Authority');

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);

    expect(fn () => AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]))->toThrow(QueryException::class);
});

test('the global Orchestrator cannot bind to a project AgentWorker', function (): void {
    $project = futureGovernanceProject('Orchestrator Worker Boundary');

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);

    $orchestrator = Agent::query()
        ->whereNull('project_id')
        ->where('role', AgentRole::Orchestrator)
        ->sole();

    expect(fn () => app(BindAgentWorker::class)->handle($worker, $orchestrator))
        ->toThrow(
            LogicException::class,
            'Agent must belong to the same project as the worker.',
        );

    expect($worker->fresh()?->agent_id)->toBeNull();
});

test('future capability work cannot cross project AgentWorker ownership boundaries', function (): void {
    $owningProject = futureGovernanceProject('Owning Project');
    $otherProject = futureGovernanceProject('Other Project');

    $worker = AgentWorker::create([
        'project_id' => $owningProject->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);

    $foreignAgent = Agent::factory()
        ->for($otherProject)
        ->create([
            'role' => AgentRole::Coder,
            'enabled' => true,
        ]);

    expect(fn () => app(BindAgentWorker::class)->handle($worker, $foreignAgent))
        ->toThrow(LogicException::class, 'Agent must belong to the same project as the worker.');

    expect($worker->fresh()?->agent_id)->toBeNull();
});
