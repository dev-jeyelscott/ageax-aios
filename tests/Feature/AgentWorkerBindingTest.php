<?php

use App\Actions\BindAgentWorker;
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
use App\Services\StaleWorkerRecovery;
use App\Services\WorkerHeartbeat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LogicException;

function p2004Project(string $name, ProjectStatus $status = ProjectStatus::Paused): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-p2-004-'.Str::uuid(),
        'status' => $status,
        'git_status' => 'clean',
    ]);
}

function p2004Agent(Project $project, AgentRole $role, string $name, bool $enabled = true): Agent
{
    return Agent::factory()->for($project)->create([
        'name' => $name,
        'role' => $role,
        'harness' => AgentHarness::Codex,
        'enabled' => $enabled,
    ]);
}

test('new project workflow workers are bound to matching default agents', function () {
    $workspace = sys_get_temp_dir().'/aios-p2-004-workspace-'.Str::uuid();
    $vault = sys_get_temp_dir().'/aios-p2-004-vault-'.Str::uuid();
    config()->set('aios.workspace_root', $workspace);
    config()->set('aios.obsidian_vault_path', $vault);
    Process::fake(['*' => Process::sequence([
        Process::result(),
        Process::result(),
        Process::result(exitCode: 1),
    ])]);

    try {
        $project = app(CreateProject::class)->handle('Bound Worker Project', 'bound-worker-project');
        $workers = $project->workers()->with('agent')->get();

        expect($workers)->toHaveCount(3);

        foreach ($workers as $worker) {
            expect($worker->agent)->not->toBeNull()
                ->and($worker->agent->project_id)->toBe($project->id)
                ->and($worker->agent->role)->toBe($worker->role)
                ->and($worker->agent->enabled)->toBeTrue();
        }
    } finally {
        File::deleteDirectory($workspace);
        File::deleteDirectory($vault);
    }
});

test('existing core workers are backfilled idempotently without changing worker or run history', function () {
    $project = p2004Project('Existing worker bindings');
    app(ProvisionDefaultProjectAgents::class)->handle($project);

    $projectManagerWorker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::ProjectManager, 'status' => 'idle']);
    $coderWorker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => 'working']);
    $reviewerWorker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Reviewer, 'status' => 'idle']);
    $coderWorker->update([
        'worker_instance_id' => (string) Str::uuid(),
        'lease_id' => (string) Str::uuid(),
        'lease_expires_at' => now()->subMinute(),
        'last_heartbeat_at' => now()->subMinutes(2),
    ]);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_worker_id' => $projectManagerWorker->id,
        'role' => AgentRole::ProjectManager,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'existing-binding-run'),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $workerIds = $project->workers()->orderBy('id')->pluck('id')->all();
    $coderRuntimeState = $coderWorker->only(['status', 'worker_instance_id', 'lease_id']);

    $migration = require database_path('migrations/2026_08_15_141500_add_agent_id_to_agent_workers_table.php');
    $migration->up();
    $migration->up();

    foreach ([$projectManagerWorker, $coderWorker, $reviewerWorker] as $worker) {
        $worker->refresh()->load('agent');

        expect($worker->agent)->not->toBeNull()
            ->and($worker->agent->project_id)->toBe($project->id)
            ->and($worker->agent->role)->toBe($worker->role)
            ->and($worker->agent->enabled)->toBeTrue();
    }

    expect($project->workers()->orderBy('id')->pluck('id')->all())->toBe($workerIds)
        ->and($coderWorker->only(['status', 'worker_instance_id', 'lease_id']))->toBe($coderRuntimeState)
        ->and(AgentRun::query()->whereKey($run->id)->exists())->toBeTrue()
        ->and(AgentRun::query()->count())->toBe(1);
});

test('workers bind only to enabled same-project agents with the same workflow role', function () {
    $project = p2004Project('Binding invariants');
    $otherProject = p2004Project('Other binding project');
    $original = p2004Agent($project, AgentRole::Coder, 'Original Coder');
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $original->id, 'status' => 'idle']);
    $binder = app(BindAgentWorker::class);

    $replacement = p2004Agent($project, AgentRole::Coder, 'Replacement Coder');
    expect($binder->handle($worker, $replacement)->agent_id)->toBe($replacement->id);

    $reviewer = p2004Agent($project, AgentRole::Reviewer, 'Wrong Role Reviewer');
    expect(fn () => $binder->handle($worker, $reviewer))
        ->toThrow(LogicException::class, 'Agent role must match the worker role.');
    expect($worker->refresh()->agent_id)->toBe($replacement->id);

    $disabled = p2004Agent($project, AgentRole::Coder, 'Disabled Coder', false);
    expect(fn () => $binder->handle($worker, $disabled))
        ->toThrow(LogicException::class, 'Disabled agents cannot be bound to workflow workers.');
    expect($worker->refresh()->agent_id)->toBe($replacement->id);

    $foreign = p2004Agent($otherProject, AgentRole::Coder, 'Foreign Coder');
    expect(fn () => $binder->handle($worker, $foreign))
        ->toThrow(LogicException::class, 'Agent must belong to the same project as the worker.');
    expect($worker->refresh()->agent_id)->toBe($replacement->id);
});

test('all core workflow roles can bind only to their matching agent role', function () {
    $project = p2004Project('Core role bindings');
    $binder = app(BindAgentWorker::class);

    foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
        $agent = p2004Agent($project, $role, 'Agent '.$role->value);
        $worker = AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => 'idle']);

        $bound = $binder->handle($worker, $agent);

        expect($bound->agent_id)->toBe($agent->id)
            ->and($bound->agent->role)->toBe($role);
    }
});

test('rebinding is blocked during active leases or runs and does not change in-progress run evidence', function () {
    $project = p2004Project('Active execution binding', ProjectStatus::Running);
    $original = p2004Agent($project, AgentRole::Coder, 'Active Original Coder');
    $replacement = p2004Agent($project, AgentRole::Coder, 'Active Replacement Coder');
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $original->id, 'status' => 'idle']);
    $binder = app(BindAgentWorker::class);
    $heartbeat = app(WorkerHeartbeat::class);

    $lease = $heartbeat->acquire($project, AgentRole::Coder, (string) Str::uuid());
    expect($lease)->not->toBeNull();
    expect(fn () => $binder->handle($worker, $replacement))
        ->toThrow(LogicException::class, 'A workflow worker with an active lease or run cannot be rebound.');
    expect($worker->refresh()->agent_id)->toBe($original->id);

    $heartbeat->release($lease);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_worker_id' => $worker->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'active-binding-run'),
        'result' => ['evidence' => 'unchanged'],
        'started_at' => now(),
    ]);
    $runEvidence = $run->refresh()->getRawOriginal();

    expect(fn () => $binder->handle($worker, $replacement))
        ->toThrow(LogicException::class, 'A workflow worker with an active lease or run cannot be rebound.');
    expect($worker->refresh()->agent_id)->toBe($original->id)
        ->and($run->refresh()->getRawOriginal())->toBe($runEvidence);

    $run->update(['status' => AgentRunStatus::Completed, 'finished_at' => now()]);

    expect($binder->handle($worker, $replacement)->agent_id)->toBe($replacement->id);
});

test('heartbeat and stale recovery preserve the selected agent binding', function () {
    $project = p2004Project('Binding recovery', ProjectStatus::Running);
    $agent = p2004Agent($project, AgentRole::Coder, 'Recovery Coder');
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $agent->id, 'status' => 'idle']);
    $heartbeat = app(WorkerHeartbeat::class);

    $lease = $heartbeat->acquire($project, AgentRole::Coder, (string) Str::uuid());
    expect($lease)->not->toBeNull()
        ->and($worker->refresh()->agent_id)->toBe($agent->id);

    expect($heartbeat->renew($lease))->toBeTrue()
        ->and($worker->refresh()->agent_id)->toBe($agent->id);

    expect($heartbeat->release($lease))->toBeTrue()
        ->and($worker->refresh()->agent_id)->toBe($agent->id);

    $worker->update([
        'status' => 'working',
        'worker_instance_id' => (string) Str::uuid(),
        'lease_id' => (string) Str::uuid(),
        'lease_expires_at' => now()->subMinute(),
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($worker->refresh()->status)->toBe('interrupted')
        ->and($worker->agent_id)->toBe($agent->id);
});

test('the existing unique project and role worker constraint is preserved', function () {
    $project = p2004Project('Unique worker slot');
    $firstAgent = p2004Agent($project, AgentRole::Coder, 'Unique First Coder');
    $secondAgent = p2004Agent($project, AgentRole::Coder, 'Unique Second Coder');

    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'agent_id' => $firstAgent->id, 'status' => 'idle']);

    expect(fn () => AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'agent_id' => $secondAgent->id,
        'status' => 'idle',
    ]))->toThrow(QueryException::class);
});
