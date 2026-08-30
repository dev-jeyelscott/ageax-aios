<?php

use App\Actions\RunCoderTask;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\WorkerHeartbeat;
use App\TaskStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * Create a running Project and first Phase fixture for P10-007 command-level concurrency coverage.
 *
 * @return array{0: Project, 1: Phase}
 */
function p10007Project(string $name, int $concurrency = 2): array
{
    $project = Project::create([
        'name' => $name,
        'path' => '/tmp/p10-007-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
        'coder_concurrency' => $concurrency,
    ]);

    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'P10-007 Bounded Concurrency',
        'objective' => 'Verify command-level bounded Coder concurrency.',
    ]);

    return [$project, $phase];
}

/**
 * Create one durable Coder worker slot for a Project.
 */
function p10007CoderWorker(Project $project, int $slot): AgentWorker
{
    return AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'slot' => $slot,
        'status' => 'idle',
    ]);
}

/**
 * Create one deterministic Task fixture with explicit file-level impact.
 *
 * @param  list<string>|null  $relevantPaths
 */
function p10007Task(
    Project $project,
    Phase $phase,
    int $position,
    ?array $relevantPaths,
    TaskStatus $status = TaskStatus::Queued,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'P10-007-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT).'-'.Str::lower(Str::random(5)),
        'position' => $position,
        'title' => "Bounded concurrency task {$position}",
        'objective' => "Verify P10-007 command scheduling for task {$position}.",
        'acceptance_criteria' => ['Command-level Coder scheduling remains AIOS-owned.'],
        'relevant_paths' => $relevantPaths,
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

/**
 * Simulate an already-running Coder execution durably holding one exact worker slot lease.
 */
function p10007ActiveClaim(Project $project, AgentWorker $worker, Task $task): void
{
    $lease = app(WorkerHeartbeat::class)->acquire(
        $project,
        AgentRole::Coder,
        (string) Str::uuid(),
        slot: (int) $worker->slot,
    );

    if ($lease === null) {
        throw new RuntimeException('Could not acquire the simulated active P10-007 Coder lease.');
    }

    $task->forceFill([
        'coder_worker_id' => $worker->id,
        'coder_worker_lease_id' => $lease->leaseId,
        'status' => TaskStatus::Coding,
        'claimed_at' => now(),
    ])->save();

    AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_worker_id' => $worker->id,
        'worker_instance_id' => $lease->workerInstanceId,
        'worker_lease_id' => $lease->leaseId,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'p10-007-'.$task->id.'-'.$lease->leaseId),
        'started_at' => now(),
    ]);
}

test('the worker command claims a second independent Task on slot two when concurrency is two', function () {
    [$project, $phase] = p10007Project('Command level independent slots');

    $firstWorker = p10007CoderWorker($project, 1);
    $secondWorker = p10007CoderWorker($project, 2);

    $firstTask = p10007Task($project, $phase, 1, ['app/Services/AlphaService.php']);
    $secondTask = p10007Task($project, $phase, 2, ['app/Http/Controllers/BetaController.php']);

    p10007ActiveClaim($project, $firstWorker, $firstTask);

    $this->mock(RunCoderTask::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')->once()->andReturn(new TaskAttempt);
    });

    expect(Artisan::call('aios:work', ['--once' => true]))->toBe(0);

    expect($secondTask->refresh()->status)->toBe(TaskStatus::Coding)
        ->and((int) $secondTask->getAttribute('coder_worker_id'))->toBe($secondWorker->id)
        ->and($firstTask->refresh()->status)->toBe(TaskStatus::Coding);
});

test('the worker command keeps unsafe overlapping Tasks serial even when concurrency is two', function () {
    [$project, $phase] = p10007Project('Command level unsafe overlap');

    $firstWorker = p10007CoderWorker($project, 1);
    p10007CoderWorker($project, 2);

    $firstTask = p10007Task($project, $phase, 1, ['app/Services/SharedService.php']);
    $secondTask = p10007Task($project, $phase, 2, ['app/Services/SharedService.php']);

    p10007ActiveClaim($project, $firstWorker, $firstTask);

    $this->mock(RunCoderTask::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('handle');
    });

    expect(Artisan::call('aios:work', ['--once' => true]))->toBe(0);

    expect($secondTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($secondTask->getAttribute('coder_worker_id'))->toBeNull();
});

test('the worker command keeps dependency-related Tasks serial even when concurrency is two', function () {
    [$project, $phase] = p10007Project('Command level dependency gate');

    $firstWorker = p10007CoderWorker($project, 1);
    p10007CoderWorker($project, 2);

    $dependency = p10007Task($project, $phase, 1, ['app/Services/FoundationService.php']);
    $dependent = p10007Task($project, $phase, 2, ['app/Services/DependentService.php']);
    $dependent->dependencies()->attach($dependency);

    p10007ActiveClaim($project, $firstWorker, $dependency);

    $this->mock(RunCoderTask::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('handle');
    });

    expect(Artisan::call('aios:work', ['--once' => true]))->toBe(0);

    expect($dependent->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($dependent->getAttribute('coder_worker_id'))->toBeNull();
});

test('lowering concurrency to one leaves an already active second slot untouched', function () {
    [$project, $phase] = p10007Project('Lower concurrency mid flight', concurrency: 2);

    $firstWorker = p10007CoderWorker($project, 1);
    $secondWorker = p10007CoderWorker($project, 2);

    $secondTask = p10007Task($project, $phase, 2, ['app/Services/SecondaryService.php']);
    p10007ActiveClaim($project, $secondWorker, $secondTask);

    $secondSlotLeaseId = $secondWorker->refresh()->lease_id;

    $project->update(['coder_concurrency' => 1]);

    $this->mock(RunCoderTask::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('handle');
    });

    expect(Artisan::call('aios:work', ['--once' => true]))->toBe(0);

    expect($secondTask->refresh()->status)->toBe(TaskStatus::Coding)
        ->and($secondWorker->refresh()->lease_id)->toBe($secondSlotLeaseId)
        ->and($firstWorker->refresh()->lease_id)->toBeNull();
});

test('the worker command respects the additional Coder slot cooldown independently of slot one', function () {
    [$project, $phase] = p10007Project('Additional slot cooldown');

    p10007CoderWorker($project, 1);
    $secondWorker = p10007CoderWorker($project, 2);
    $secondWorker->update(['task_completed_at' => now()]);

    p10007Task($project, $phase, 1, ['app/Services/CooldownService.php']);

    $this->mock(RunCoderTask::class, function (MockInterface $mock): void {
        $mock->shouldReceive('handle')->once()->andReturn(new TaskAttempt);
    });

    expect(Artisan::call('aios:work', ['--once' => true]))->toBe(0);

    expect($secondWorker->refresh()->lease_id)->toBeNull()
        ->and($secondWorker->last_heartbeat_at)->toBeNull();
});
