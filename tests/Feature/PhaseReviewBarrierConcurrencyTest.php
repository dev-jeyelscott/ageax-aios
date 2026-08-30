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
use Illuminate\Support\Str;

/**
 * Create a running Project with concurrency 2 and its first Phase for rolling-review coverage.
 *
 * @return array{0: Project, 1: Phase}
 */
function rollingReviewConcurrencyProject(string $name): array
{
    $project = Project::create([
        'name' => $name,
        'path' => '/tmp/phase-review-barrier-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
        'coder_concurrency' => 2,
    ]);

    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'Rolling review with concurrency',
        'objective' => 'Verify rolling review remains available across concurrent Coder slots.',
    ]);

    return [$project, $phase];
}

test('the Reviewer may claim a ready Task while an independent concurrent Task is still coding', function () {
    [$project, $phase] = rollingReviewConcurrencyProject('Rolling review during concurrency');

    $firstWorker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'slot' => 1, 'status' => 'idle']);
    $secondWorker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'slot' => 2, 'status' => 'idle']);

    $firstTask = Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'PRB-001',
        'position' => 1,
        'title' => 'First concurrent task',
        'objective' => 'Reach ready_for_review.',
        'acceptance_criteria' => ['Independent concurrent Task completes.'],
        'relevant_paths' => ['app/Services/BarrierAlpha.php'],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
        'status' => TaskStatus::ReadyForReview,
    ]);

    $secondTask = Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'PRB-002',
        'position' => 2,
        'title' => 'Second concurrent task',
        'objective' => 'Still coding while the first is ready for review.',
        'acceptance_criteria' => ['Independent concurrent Task remains in progress.'],
        'relevant_paths' => ['app/Services/BarrierBeta.php'],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);

    $secondLease = app(WorkerHeartbeat::class)->acquire($project, AgentRole::Coder, (string) Str::uuid(), slot: 2);
    $claimedSecondTask = app(ClaimTask::class)->handle($project, AgentRole::Coder, $secondLease);

    expect($claimedSecondTask?->id)->toBe($secondTask->id)
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Coding);

    AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $secondTask->id,
        'agent_worker_id' => $secondLease->workerId,
        'worker_instance_id' => $secondLease->workerInstanceId,
        'worker_lease_id' => $secondLease->leaseId,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'prb-second-active'),
        'started_at' => now(),
    ]);

    $reviewedTask = app(ClaimTask::class)->handle($project, AgentRole::Reviewer);

    expect($reviewedTask?->id)->toBe($firstTask->id)
        ->and($reviewedTask?->refresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::Coding);
});

test('the earliest ready concurrent Task is reviewed first', function () {
    [$project, $phase] = rollingReviewConcurrencyProject('Earliest rolling review task');

    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'slot' => 1, 'status' => 'idle']);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'slot' => 2, 'status' => 'idle']);

    $firstTask = Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'PRB-101',
        'position' => 1,
        'title' => 'First completed concurrent task',
        'objective' => 'Ready for review.',
        'acceptance_criteria' => ['Independent concurrent Task completes.'],
        'relevant_paths' => ['app/Services/BarrierGamma.php'],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
        'status' => TaskStatus::ReadyForReview,
    ]);

    Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'PRB-102',
        'position' => 2,
        'title' => 'Second completed concurrent task',
        'objective' => 'Also ready for review.',
        'acceptance_criteria' => ['Independent concurrent Task completes.'],
        'relevant_paths' => ['app/Services/BarrierDelta.php'],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only this bounded Task.',
        'context_capsule' => [],
        'status' => TaskStatus::ReadyForReview,
    ]);

    $reviewedTask = app(ClaimTask::class)->handle($project, AgentRole::Reviewer);

    expect($reviewedTask?->id)->toBe($firstTask->id)
        ->and($reviewedTask?->refresh()->status)->toBe(TaskStatus::Reviewing);
});
