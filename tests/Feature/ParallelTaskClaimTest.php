<?php

use App\Actions\ClaimTask;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\WorkerHeartbeat;
use App\TaskStatus;
use App\WorkerLease;
use Illuminate\Support\Str;

/**
 * Create a running Project and its first Phase for P10-004 claim tests.
 *
 * @return array{0: Project, 1: Phase}
 */
function p10004Project(string $name): array
{
    $project = Project::create([
        'name' => $name,
        'path' => '/tmp/p10-004-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'P10-004 Parallel Claiming',
        'objective' => 'Verify deterministic Coder Task admission.',
    ]);

    return [$project, $phase];
}

/**
 * Create one deterministic Task fixture with explicit file-level impact for parallel-safety evaluation.
 *
 * @param  list<string>|null  $relevantPaths
 */
function p10004Task(
    Project $project,
    Phase $phase,
    int $position,
    ?array $relevantPaths,
    TaskStatus $status = TaskStatus::Queued,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'P10-004-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT).'-'.Str::lower(Str::random(5)),
        'position' => $position,
        'title' => "Parallel claim task {$position}",
        'objective' => "Verify P10-004 claim behavior for task {$position}.",
        'acceptance_criteria' => [
            'Task claiming remains deterministic and AIOS-owned.',
        ],
        'relevant_paths' => $relevantPaths,
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

/**
 * Create one durable Coder worker slot for a Project.
 */
function p10004CoderWorker(Project $project, int $slot): AgentWorker
{
    return AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'slot' => $slot,
        'status' => 'idle',
    ]);
}

/**
 * Acquire one exact Coder worker lease for the supplied slot.
 */
function p10004Lease(Project $project, AgentWorker $worker): WorkerLease
{
    $lease = app(WorkerHeartbeat::class)->acquire(
        $project,
        AgentRole::Coder,
        (string) Str::uuid(),
        slot: (int) $worker->slot,
    );

    if (! $lease instanceof WorkerLease) {
        throw new RuntimeException('Could not acquire the P10-004 Coder worker lease.');
    }

    return $lease;
}

/**
 * Claim one Coder Task through the explicit P10-004 lease-bound primitive and require success.
 */
function p10004ConcurrentClaim(Project $project, WorkerLease $lease): Task
{
    $task = app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $lease,
    );

    if (! $task instanceof Task) {
        throw new RuntimeException('Expected the P10-004 concurrent Coder claim to succeed.');
    }

    return $task;
}

/**
 * Record the running Coder execution evidence required by the existing deterministic safety evaluator.
 */
function p10004RunningCoderRun(Task $task, WorkerLease $lease): AgentRun
{
    return AgentRun::create([
        'project_id' => $task->project_id,
        'task_id' => $task->id,
        'agent_worker_id' => $lease->workerId,
        'worker_instance_id' => $lease->workerInstanceId,
        'worker_lease_id' => $lease->leaseId,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'p10-004-'.$task->id.'-'.$lease->leaseId),
        'started_at' => now(),
    ]);
}

test('two independent Tasks can hold distinct Coder claims once the first claim has active execution evidence', function () {
    [$project, $phase] = p10004Project('Independent concurrent claims');

    $firstTask = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/AlphaService.php'],
    );
    $secondTask = p10004Task(
        $project,
        $phase,
        2,
        ['app/Http/Controllers/BetaController.php'],
    );

    $firstWorker = p10004CoderWorker($project, 1);
    $secondWorker = p10004CoderWorker($project, 2);
    $firstLease = p10004Lease($project, $firstWorker);
    $secondLease = p10004Lease($project, $secondWorker);

    $firstClaim = p10004ConcurrentClaim($project, $firstLease);

    expect($firstClaim->id)->toBe($firstTask->id);

    p10004RunningCoderRun($firstClaim, $firstLease);

    $secondClaim = app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $secondLease,
    );

    expect($secondClaim?->id)->toBe($secondTask->id)
        ->and($firstTask->refresh()->status)->toBe(TaskStatus::Coding)
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Coding)
        ->and((int) $firstTask->getAttribute('coder_worker_id'))->toBe($firstWorker->id)
        ->and($firstTask->getAttribute('coder_worker_lease_id'))->toBe($firstLease->leaseId)
        ->and((int) $secondTask->getAttribute('coder_worker_id'))->toBe($secondWorker->id)
        ->and($secondTask->getAttribute('coder_worker_lease_id'))->toBe($secondLease->leaseId);
});

test('claim to run uncertainty safely falls back to serial and can retry after execution evidence exists', function () {
    [$project, $phase] = p10004Project('Claim to run fallback');

    $firstTask = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/FirstService.php'],
    );
    $secondTask = p10004Task(
        $project,
        $phase,
        2,
        ['app/Services/SecondService.php'],
    );

    $firstWorker = p10004CoderWorker($project, 1);
    $secondWorker = p10004CoderWorker($project, 2);
    $firstLease = p10004Lease($project, $firstWorker);
    $secondLease = p10004Lease($project, $secondWorker);

    $firstClaim = p10004ConcurrentClaim($project, $firstLease);

    expect($firstClaim->id)->toBe($firstTask->id)
        ->and(app(ClaimTask::class)->handle(
            $project,
            AgentRole::Coder,
            $secondLease,
        ))->toBeNull()
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($secondTask->getAttribute('coder_worker_id'))->toBeNull();

    p10004RunningCoderRun($firstClaim, $firstLease);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $secondLease,
    )?->id)->toBe($secondTask->id);
});

test('the same Task cannot be claimed twice by different Coder slots', function () {
    [$project, $phase] = p10004Project('Duplicate Task claim');

    $task = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/SingleService.php'],
    );

    $firstWorker = p10004CoderWorker($project, 1);
    $secondWorker = p10004CoderWorker($project, 2);
    $firstLease = p10004Lease($project, $firstWorker);
    $secondLease = p10004Lease($project, $secondWorker);

    $firstClaim = p10004ConcurrentClaim($project, $firstLease);

    expect($firstClaim->id)->toBe($task->id);

    p10004RunningCoderRun($firstClaim, $firstLease);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $secondLease,
    ))->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Coding)
        ->and((int) $task->getAttribute('coder_worker_id'))->toBe($firstWorker->id)
        ->and($task->getAttribute('coder_worker_lease_id'))->toBe($firstLease->leaseId);
});

test('one Coder worker lease cannot own two active Tasks', function () {
    [$project, $phase] = p10004Project('One Task per Coder slot');

    $firstTask = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/PrimaryService.php'],
    );
    $secondTask = p10004Task(
        $project,
        $phase,
        2,
        ['app/Services/SecondaryService.php'],
    );

    $worker = p10004CoderWorker($project, 1);
    $lease = p10004Lease($project, $worker);

    $firstClaim = p10004ConcurrentClaim($project, $lease);

    expect($firstClaim->id)->toBe($firstTask->id);

    p10004RunningCoderRun($firstClaim, $lease);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $lease,
    ))->toBeNull()
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($secondTask->getAttribute('coder_worker_id'))->toBeNull();
});

test('unsafe overlapping Task scope remains serial without mutating the candidate', function () {
    [$project, $phase] = p10004Project('Unsafe overlapping claim');

    $firstTask = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/SharedService.php'],
    );
    $secondTask = p10004Task(
        $project,
        $phase,
        2,
        ['app/Services/SharedService.php'],
    );

    $firstWorker = p10004CoderWorker($project, 1);
    $secondWorker = p10004CoderWorker($project, 2);
    $firstLease = p10004Lease($project, $firstWorker);
    $secondLease = p10004Lease($project, $secondWorker);

    $firstClaim = p10004ConcurrentClaim($project, $firstLease);

    expect($firstClaim->id)->toBe($firstTask->id);

    p10004RunningCoderRun($firstClaim, $firstLease);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $secondLease,
    ))->toBeNull()
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($secondTask->getAttribute('coder_worker_id'))->toBeNull()
        ->and($secondTask->attempts()->count())->toBe(0);
});

test('unknown Task impact remains serial without mutating the candidate', function () {
    [$project, $phase] = p10004Project('Unknown impact fallback');

    $firstTask = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/KnownService.php'],
    );
    $secondTask = p10004Task(
        $project,
        $phase,
        2,
        null,
    );

    $firstWorker = p10004CoderWorker($project, 1);
    $secondWorker = p10004CoderWorker($project, 2);
    $firstLease = p10004Lease($project, $firstWorker);
    $secondLease = p10004Lease($project, $secondWorker);

    $firstClaim = p10004ConcurrentClaim($project, $firstLease);

    expect($firstClaim->id)->toBe($firstTask->id);

    p10004RunningCoderRun($firstClaim, $firstLease);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $secondLease,
    ))->toBeNull()
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($secondTask->getAttribute('coder_worker_id'))->toBeNull();
});

test('unfinished dependencies still prevent concurrent Coder claims', function () {
    [$project, $phase] = p10004Project('Dependency gate');

    $dependency = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/FoundationService.php'],
    );
    $dependent = p10004Task(
        $project,
        $phase,
        2,
        ['app/Services/DependentService.php'],
    );
    $dependent->dependencies()->attach($dependency);

    $firstWorker = p10004CoderWorker($project, 1);
    $secondWorker = p10004CoderWorker($project, 2);
    $firstLease = p10004Lease($project, $firstWorker);
    $secondLease = p10004Lease($project, $secondWorker);

    $firstClaim = p10004ConcurrentClaim($project, $firstLease);

    expect($firstClaim->id)->toBe($dependency->id);

    p10004RunningCoderRun($firstClaim, $firstLease);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $secondLease,
    ))->toBeNull()
        ->and($dependent->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($dependent->getAttribute('coder_worker_id'))->toBeNull();
});

test('later phase Tasks remain closed while the current phase is unresolved', function () {
    [$project, $firstPhase] = p10004Project('Phase gate');

    $secondPhase = Phase::create([
        'project_id' => $project->id,
        'position' => 2,
        'title' => 'Later Phase',
        'objective' => 'Remain closed until the first Phase completes.',
    ]);

    p10004Task(
        $project,
        $firstPhase,
        1,
        ['app/Services/ReviewBarrier.php'],
        TaskStatus::ReadyForReview,
    );
    $laterTask = p10004Task(
        $project,
        $secondPhase,
        2,
        ['app/Services/LaterService.php'],
    );

    $worker = p10004CoderWorker($project, 1);
    $lease = p10004Lease($project, $worker);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $lease,
    ))->toBeNull()
        ->and($laterTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($laterTask->getAttribute('coder_worker_id'))->toBeNull();
});

test('invalid Coder lease ownership cannot create a concurrent claim', function () {
    [$project, $phase] = p10004Project('Invalid Coder lease');

    $task = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/LeaseGuard.php'],
    );

    $worker = p10004CoderWorker($project, 1);
    $lease = p10004Lease($project, $worker);

    $worker->update([
        'lease_expires_at' => now()->subSecond(),
    ]);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $lease,
    ))->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($task->getAttribute('coder_worker_id'))->toBeNull();

    $worker->update([
        'lease_expires_at' => now()->addMinute(),
    ]);

    $mismatchedLease = new WorkerLease(
        $worker->id,
        $lease->workerInstanceId,
        (string) Str::uuid(),
    );

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $mismatchedLease,
    ))->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Queued);
});

test('serial Coder claiming remains the default and concurrent admission will not join unowned serial work', function () {
    [$project, $phase] = p10004Project('Serial fallback');

    $firstTask = p10004Task(
        $project,
        $phase,
        1,
        ['app/Services/SerialService.php'],
    );
    $secondTask = p10004Task(
        $project,
        $phase,
        2,
        ['app/Services/ParallelCandidate.php'],
    );

    $serialClaim = app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
    );

    expect($serialClaim?->id)->toBe($firstTask->id)
        ->and($firstTask->refresh()->getAttribute('coder_worker_id'))->toBeNull();

    $worker = p10004CoderWorker($project, 2);
    $lease = p10004Lease($project, $worker);

    expect(app(ClaimTask::class)->handle(
        $project,
        AgentRole::Coder,
        $lease,
    ))->toBeNull()
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Queued)
        ->and($secondTask->getAttribute('coder_worker_id'))->toBeNull();
});

test('Reviewer claiming remains serial and unchanged', function () {
    [$project, $phase] = p10004Project('Reviewer remains serial');

    $firstTask = p10004Task(
        $project,
        $phase,
        1,
        ['app/FirstReview.php'],
        TaskStatus::ReadyForReview,
    );
    p10004Task(
        $project,
        $phase,
        2,
        ['app/SecondReview.php'],
        TaskStatus::ReadyForReview,
    );

    $firstClaim = app(ClaimTask::class)->handle(
        $project,
        AgentRole::Reviewer,
    );
    $secondClaim = app(ClaimTask::class)->handle(
        $project,
        AgentRole::Reviewer,
    );

    expect($firstClaim?->id)->toBe($firstTask->id)
        ->and($secondClaim)->toBeNull();
});
