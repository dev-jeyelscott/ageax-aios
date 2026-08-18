<?php

use App\Actions\CreateProject;
use App\Actions\ProvisionDefaultProjectAgents;
use App\AgentHarness;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\ProjectStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

function createDefaultAgentProvisioningProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-default-agent-project-'.Str::uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
}

test('new projects receive exactly one default Codex agent for each core role', function () {
    $workspace = sys_get_temp_dir().'/aios-default-agent-workspace-'.Str::uuid();
    $vault = sys_get_temp_dir().'/aios-default-agent-vault-'.Str::uuid();
    config()->set('aios.workspace_root', $workspace);
    config()->set('aios.obsidian_vault_path', $vault);
    Process::fake(['*' => Process::sequence([
        Process::result(),
        Process::result(),
        Process::result(exitCode: 1),
    ])]);

    try {
        $project = app(CreateProject::class)->handle('Default Agent Project', 'example');

        expect($project->agents()->count())->toBe(3)
            ->and($project->workers()->count())->toBe(3)
            ->and($project->agents()->where('name', 'Project Manager')->where('role', AgentRole::ProjectManager->value)->where('harness', AgentHarness::Codex->value)->where('enabled', true)->count())->toBe(1)
            ->and($project->agents()->where('name', 'Coder')->where('role', AgentRole::Coder->value)->where('harness', AgentHarness::Codex->value)->where('enabled', true)->count())->toBe(1)
            ->and($project->agents()->where('name', 'Reviewer')->where('role', AgentRole::Reviewer->value)->where('harness', AgentHarness::Codex->value)->where('enabled', true)->count())->toBe(1)
            ->and($project->agents()->where('configuration_version', 1)->count())->toBe(3);
    } finally {
        File::deleteDirectory($workspace);
        File::deleteDirectory($vault);
    }
});

test('default agent provisioning is idempotent and does not overwrite later configuration changes', function () {
    $project = createDefaultAgentProvisioningProject('Idempotent provisioning');
    $provisioner = app(ProvisionDefaultProjectAgents::class);

    $provisioner->handle($project);

    $coder = $project->agents()->where('name', 'Coder')->sole();
    $coder->update(['harness' => AgentHarness::ClaudeCode]);

    $provisioner->handle($project);

    expect($project->agents()->count())->toBe(3)
        ->and($project->agents()->where('name', 'Project Manager')->count())->toBe(1)
        ->and($project->agents()->where('name', 'Coder')->count())->toBe(1)
        ->and($project->agents()->where('name', 'Reviewer')->count())->toBe(1)
        ->and($coder->refresh()->harness)->toBe(AgentHarness::ClaudeCode)
        ->and($coder->configuration_version)->toBe(2);
});

test('the backfill safely provisions existing projects without changing workers or run history', function () {
    $project = createDefaultAgentProvisioningProject('Existing project backfill');

    $projectManagerWorker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => 'idle',
    ]);
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Reviewer,
        'status' => 'idle',
    ]);

    $existingCoder = Agent::factory()->for($project)->create([
        'name' => 'Coder',
        'role' => AgentRole::Coder,
        'harness' => AgentHarness::ClaudeCode,
    ]);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_worker_id' => $projectManagerWorker->id,
        'role' => AgentRole::ProjectManager,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'existing-run'),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $workerIds = $project->workers()->orderBy('id')->pluck('id')->all();

    $migration = require database_path('migrations/2026_08_15_135200_backfill_default_project_agents.php');
    $migration->up();
    $migration->up();

    expect($project->agents()->count())->toBe(3)
        ->and($project->agents()->where('name', 'Project Manager')->where('role', AgentRole::ProjectManager->value)->where('harness', AgentHarness::Codex->value)->count())->toBe(1)
        ->and($project->agents()->where('name', 'Coder')->where('role', AgentRole::Coder->value)->count())->toBe(1)
        ->and($existingCoder->refresh()->harness)->toBe(AgentHarness::ClaudeCode)
        ->and($project->agents()->where('name', 'Reviewer')->where('role', AgentRole::Reviewer->value)->where('harness', AgentHarness::Codex->value)->count())->toBe(1)
        ->and($project->workers()->orderBy('id')->pluck('id')->all())->toBe($workerIds)
        ->and(AgentRun::query()->whereKey($run->id)->exists())->toBeTrue()
        ->and(AgentRun::query()->count())->toBe(1);
});

test('creating a new project also seeds the dedicated default skill set for each core agent', function () {
    $workspace = sys_get_temp_dir().'/aios-default-skills-workspace-'.Str::uuid();
    $vault = sys_get_temp_dir().'/aios-default-skills-vault-'.Str::uuid();
    config()->set('aios.workspace_root', $workspace);
    config()->set('aios.obsidian_vault_path', $vault);
    Process::fake(['*' => Process::sequence([
        Process::result(),
        Process::result(),
        Process::result(exitCode: 1),
    ])]);

    try {
        $project = app(CreateProject::class)->handle('Default Skills Project', 'skills-example');

        expect($project->skills()->count())->toBe(24);

        foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
            $agent = $project->agents()->where('role', $role)->sole();

            expect($agent->skills()->count())->toBe(5);
        }
    } finally {
        File::deleteDirectory($workspace);
        File::deleteDirectory($vault);
    }
});

test('linking an existing project also seeds the dedicated default skill set for each core agent', function () {
    $workspace = sys_get_temp_dir().'/aios-default-skills-existing-'.Str::uuid();
    $vault = sys_get_temp_dir().'/aios-default-skills-existing-vault-'.Str::uuid();
    config()->set('aios.workspace_root', $workspace);
    config()->set('aios.obsidian_vault_path', $vault);
    mkdir($workspace.'/linked-project', 0755, true);
    Process::fake(['*' => Process::sequence([
        Process::result('true'),
        Process::result(),
        Process::result(exitCode: 1),
    ])]);

    try {
        $project = app(CreateProject::class)->handle('Linked Skills Project', 'linked-project', true);

        expect($project->skills()->count())->toBe(24);

        foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
            $agent = $project->agents()->where('role', $role)->sole();

            expect($agent->skills()->count())->toBe(5);
        }
    } finally {
        File::deleteDirectory($workspace);
        File::deleteDirectory($vault);
    }
});

test('default agent provisioning participates in the project creation transaction', function () {
    $workspace = sys_get_temp_dir().'/aios-default-agent-rollback-workspace-'.Str::uuid();
    $vault = sys_get_temp_dir().'/aios-default-agent-rollback-vault-'.Str::uuid();
    config()->set('aios.workspace_root', $workspace);
    config()->set('aios.obsidian_vault_path', $vault);
    Process::fake(['*' => Process::sequence([
        Process::result(),
        Process::result(),
        Process::result(exitCode: 1),
    ])]);

    $existingAgentCount = Agent::query()->count();

    mock(ProvisionDefaultProjectAgents::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('Provisioning failed.'));

    try {
        expect(fn () => app(CreateProject::class)->handle('Rollback Project', 'rollback-project'))
            ->toThrow(RuntimeException::class, 'Provisioning failed.');

        expect(Project::query()->count())->toBe(0)
            ->and(Agent::query()->count())->toBe($existingAgentCount)
            ->and(Agent::query()->whereNotNull('project_id')->count())->toBe(0)
            ->and(AgentWorker::query()->count())->toBe(0)
            ->and(AgentRun::query()->count())->toBe(0);
    } finally {
        File::deleteDirectory($workspace);
        File::deleteDirectory($vault);
    }
});
