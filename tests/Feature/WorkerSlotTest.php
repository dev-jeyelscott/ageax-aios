<?php

use App\Actions\BindAgentWorker;
use App\AgentHarness;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\StaleWorkerRecovery;
use App\Services\WorkerHeartbeat;
use App\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;

/**
 * Create a project fixture for worker-slot regression coverage.
 */
function p10003Project(
    string $name,
    ProjectStatus $status = ProjectStatus::Running,
): Project {
    return Project::create([
        'name' => $name,
        'path' => '/tmp/p10-003-'.fake()->uuid(),
        'status' => $status,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one durable worker slot for the requested project role.
 */
function p10003Worker(
    Project $project,
    AgentRole $role = AgentRole::Coder,
    int $slot = 1,
    ?int $agentId = null,
): AgentWorker {
    return AgentWorker::create([
        'project_id' => $project->id,
        'role' => $role,
        'slot' => $slot,
        'agent_id' => $agentId,
        'status' => 'idle',
    ]);
}

/**
 * Create one claimed Task fixture for slot-aware stale recovery coverage.
 */
function p10003Task(
    Project $project,
    string $key,
    int $position,
    TaskStatus $status = TaskStatus::Coding,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => 'Worker slot task '.$key,
        'objective' => 'Verify exact worker slot recovery.',
        'acceptance_criteria' => [
            'Only the owning worker slot may recover this task.',
        ],
        'implementation_prompt' => 'Exercise worker slot recovery.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('existing workers default to slot one and duplicate role slots are rejected', function () {
    $project = p10003Project('Worker slot uniqueness');

    $slotOne = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);
    $slotTwo = p10003Worker($project, slot: 2);

    expect($slotOne->refresh()->slot)->toBe(1)
        ->and($slotTwo->slot)->toBe(2)
        ->and(
            $project->workers()
                ->where('role', AgentRole::Coder)
                ->count(),
        )->toBe(2);

    expect(fn () => p10003Worker($project, slot: 2))
        ->toThrow(QueryException::class);
});

test('coder slots acquire renew and release independent atomic leases', function () {
    $project = p10003Project('Independent coder leases');
    $slotOne = p10003Worker($project, slot: 1);
    $slotTwo = p10003Worker($project, slot: 2);
    $heartbeat = app(WorkerHeartbeat::class);

    $firstLease = $heartbeat->acquire(
        $project,
        AgentRole::Coder,
        fake()->uuid(),
        slot: 1,
    );
    $secondLease = $heartbeat->acquire(
        $project,
        AgentRole::Coder,
        fake()->uuid(),
        slot: 2,
    );

    expect($firstLease)->not->toBeNull()
        ->and($secondLease)->not->toBeNull()
        ->and($firstLease?->workerId)->toBe($slotOne->id)
        ->and($secondLease?->workerId)->toBe($slotTwo->id)
        ->and(
            $heartbeat->acquire(
                $project,
                AgentRole::Coder,
                fake()->uuid(),
                slot: 1,
            ),
        )->toBeNull()
        ->and(
            $heartbeat->acquire(
                $project,
                AgentRole::Coder,
                fake()->uuid(),
                slot: 2,
            ),
        )->toBeNull();

    expect($heartbeat->renew($secondLease))->toBeTrue()
        ->and($heartbeat->release($firstLease))->toBeTrue()
        ->and($slotOne->refresh()->lease_id)->toBeNull()
        ->and($slotTwo->refresh()->lease_id)
        ->toBe($secondLease->leaseId);

    $slotTwoClaim = $project->auditEvents()
        ->where('event_type', 'worker.lease_claimed')
        ->get()
        ->first(
            fn ($event): bool => (int) data_get(
                $event->payload,
                'slot',
            ) === 2,
        );

    expect($slotTwoClaim)->not->toBeNull()
        ->and(
            (int) data_get(
                $slotTwoClaim?->payload,
                'agent_worker_id',
            ),
        )->toBe($slotTwo->id);
});

test('only coder role accepts execution slots above one', function () {
    $project = p10003Project('Bounded role slots');
    $heartbeat = app(WorkerHeartbeat::class);

    expect(fn () => $heartbeat->acquire(
        $project,
        AgentRole::Reviewer,
        fake()->uuid(),
        slot: 2,
    ))->toThrow(
        LogicException::class,
        'Only Coder workers may use execution slots above 1.',
    );

    expect(fn () => $heartbeat->acquire(
        $project,
        AgentRole::ProjectManager,
        fake()->uuid(),
        slot: 2,
    ))->toThrow(
        LogicException::class,
        'Only Coder workers may use execution slots above 1.',
    );

    expect(fn () => $heartbeat->acquire(
        $project,
        AgentRole::Coder,
        fake()->uuid(),
        slot: 0,
    ))->toThrow(
        LogicException::class,
        'Worker slot must be a positive integer.',
    );
});

test('slot leases preserve the existing agent binding semantics', function () {
    $project = p10003Project('Slot agent binding');
    $agent = Agent::factory()->for($project)->create([
        'name' => 'Slot Two Coder',
        'role' => AgentRole::Coder,
        'harness' => AgentHarness::Codex,
        'enabled' => true,
    ]);
    $worker = p10003Worker(
        $project,
        AgentRole::Coder,
        2,
        $agent->id,
    );
    $heartbeat = app(WorkerHeartbeat::class);

    $lease = $heartbeat->acquire(
        $project,
        AgentRole::Coder,
        fake()->uuid(),
        slot: 2,
    );

    expect($lease)->not->toBeNull()
        ->and($heartbeat->renew($lease))->toBeTrue()
        ->and($worker->refresh()->agent_id)->toBe($agent->id)
        ->and($heartbeat->release($lease))->toBeTrue()
        ->and($worker->refresh()->agent_id)->toBe($agent->id);

    expect(
        app(BindAgentWorker::class)
            ->handle($worker, $agent)
            ->agent_id,
    )->toBe($agent->id);
});

test('stale recovery isolates an expired coder slot from another live coder slot', function () {
    $project = p10003Project('Slot isolated recovery');
    $slotOne = p10003Worker($project, slot: 1);
    $slotTwo = p10003Worker($project, slot: 2);
    $heartbeat = app(WorkerHeartbeat::class);

    $liveLease = $heartbeat->acquire(
        $project,
        AgentRole::Coder,
        fake()->uuid(),
        slot: 1,
    );
    $deadLeaseId = fake()->uuid();

    $slotTwo->update([
        'status' => 'working',
        'worker_instance_id' => fake()->uuid(),
        'lease_id' => $deadLeaseId,
        'lease_expires_at' => now()->subMinute(),
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);

    $liveTask = p10003Task($project, 'TASK-SLOT-1', 1);
    $staleTask = p10003Task($project, 'TASK-SLOT-2', 2);

    $liveAttempt = TaskAttempt::create([
        'task_id' => $liveTask->id,
        'number' => 1,
        'status' => 'running',
        'started_at' => now(),
    ]);
    $staleAttempt = TaskAttempt::create([
        'task_id' => $staleTask->id,
        'number' => 1,
        'status' => 'running',
        'started_at' => now()->subMinutes(5),
    ]);

    $liveRun = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $liveTask->id,
        'agent_worker_id' => $slotOne->id,
        'worker_instance_id' => $liveLease?->workerInstanceId,
        'worker_lease_id' => $liveLease?->leaseId,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'live-slot-one'),
        'started_at' => now(),
    ]);
    $staleRun = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $staleTask->id,
        'agent_worker_id' => $slotTwo->id,
        'worker_lease_id' => $deadLeaseId,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'stale-slot-two'),
        'started_at' => now()->subMinutes(5),
    ]);

    expect(
        app(StaleWorkerRecovery::class)->recover($project, 60),
    )->toBe(1)
        ->and($liveTask->refresh()->status)
        ->toBe(TaskStatus::Coding)
        ->and($liveAttempt->refresh()->status)
        ->toBe('running')
        ->and($liveRun->refresh()->status)
        ->toBe(AgentRunStatus::Running)
        ->and($slotOne->refresh()->lease_id)
        ->toBe($liveLease?->leaseId)
        ->and($staleTask->refresh()->status)
        ->toBe(TaskStatus::Failed)
        ->and($staleAttempt->refresh()->status)
        ->toBe('interrupted')
        ->and($staleRun->refresh()->status)
        ->toBe(AgentRunStatus::Interrupted)
        ->and($slotTwo->refresh()->status)
        ->toBe('interrupted');

    $slotTwoRecovery = $project->auditEvents()
        ->where('event_type', 'worker.recovered')
        ->get()
        ->first(
            fn ($event): bool => (int) data_get(
                $event->payload,
                'slot',
            ) === 2,
        );

    expect($slotTwoRecovery)->not->toBeNull()
        ->and(
            (int) data_get(
                $slotTwoRecovery?->payload,
                'agent_worker_id',
            ),
        )->toBe($slotTwo->id);
});

test('normal worker command remains pinned to slot one by default', function () {
    $project = p10003Project('Serial scheduler default');
    $slotOne = p10003Worker($project, slot: 1);
    $slotTwo = p10003Worker($project, slot: 2);

    expect(
        Artisan::call('aios:work', ['--once' => true]),
    )->toBe(0)
        ->and($slotOne->refresh()->last_heartbeat_at)
        ->not->toBeNull()
        ->and($slotOne->lease_id)
        ->toBeNull()
        ->and($slotTwo->refresh()->last_heartbeat_at)
        ->toBeNull()
        ->and($slotTwo->lease_id)
        ->toBeNull();
});
