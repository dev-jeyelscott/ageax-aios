<?php

use App\Actions\ClaimTask;
use App\Actions\RequeueBlockedTask;
use App\Actions\RunProjectManager;
use App\Actions\TransitionTask;
use App\AgentRole;
use App\Jobs\ProcessRoadmap;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapAttempt;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use App\Services\TaskWorkflow;
use App\TaskStatus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

function createWorkflowTask(Project $project, int $position): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Task {$position}",
        'objective' => "Implement task {$position}.",
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement the task.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

test('phase-less legacy tasks keep approval-gated dependency behavior', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $secondTask->dependencies()->attach($firstTask);

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($firstTask->id);

    $transitionTask->handle($firstTask, TaskStatus::Validating);
    $transitionTask->handle($firstTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Coder))->toBeNull();
    expect($claimTask->handle($project, AgentRole::Reviewer)?->id)->toBe($firstTask->id);

    $transitionTask->handle($firstTask, TaskStatus::Done);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);
});

test('a rejected review returns the same task to the coder with a legal transition', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = createWorkflowTask($project, 1);
    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    $claimTask->handle($project, AgentRole::Coder);
    $transitionTask->handle($task, TaskStatus::Validating);
    $transitionTask->handle($task, TaskStatus::ReadyForReview);
    $claimTask->handle($project, AgentRole::Reviewer);
    $transitionTask->handle($task, TaskStatus::ChangesRequired);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($task->id);
});

test('an active reviewer does not occupy the separate coder lane', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);
    $reviewingTask = createWorkflowTask($project, 1);
    $coderTask = createWorkflowTask($project, 2);

    $reviewingTask->update(['phase_id' => $phase->id, 'status' => TaskStatus::Reviewing]);
    $coderTask->update(['phase_id' => $phase->id, 'status' => TaskStatus::ChangesRequired]);

    $claimedTask = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    expect($claimedTask?->id)->toBe($coderTask->id)
        ->and($coderTask->refresh()->status)->toBe(TaskStatus::Coding)
        ->and($reviewingTask->refresh()->status)->toBe(TaskStatus::Reviewing);
});

test('an exhausted reviewer operational retry blocks and can be requeued for review', function () {
    config()->set('aios.max_reviewer_attempts', 1);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = createWorkflowTask($project, 1);
    app(TransitionTask::class)->handle($task, TaskStatus::Coding);
    app(TransitionTask::class)->handle($task, TaskStatus::Validating);
    app(TransitionTask::class)->handle($task, TaskStatus::ReadyForReview);
    app(TransitionTask::class)->handle($task, TaskStatus::Reviewing);

    app(TaskWorkflow::class)->recordReviewerOperationalFailure($task, null, ['reason' => 'invalid_structured_decision', 'exit_code' => 0]);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->auditEvents()->where('event_type', 'review.retry_exhausted')->exists())->toBeTrue();

    app(RequeueBlockedTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::ReadyForReview);
});

test('coder batches the current phase before reviewer claims tasks in position order', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $firstPhase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);
    $secondPhase = Phase::create(['project_id' => $project->id, 'position' => 2, 'title' => 'Delivery', 'objective' => 'Deliver the feature.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $thirdTask = createWorkflowTask($project, 3);

    $firstTask->update(['phase_id' => $firstPhase->id]);
    $secondTask->update(['phase_id' => $firstPhase->id]);
    $thirdTask->update(['phase_id' => $secondPhase->id]);

    $secondTask->dependencies()->attach($firstTask);

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($firstTask->id);

    $transitionTask->handle($firstTask, TaskStatus::Validating);
    $transitionTask->handle($firstTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Reviewer))->toBeNull()
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);

    $transitionTask->handle($secondTask, TaskStatus::Validating);
    $transitionTask->handle($secondTask, TaskStatus::ReadyForReview);

    expect(
        $claimTask->handle($project, AgentRole::Coder),
    )->toBeNull('the next phase must remain closed even when its first task has no explicit dependency');

    $firstReviewTask = $claimTask->handle($project, AgentRole::Reviewer);

    expect($firstReviewTask?->id)->toBe($firstTask->id)
        ->and($claimTask->handle($project, AgentRole::Reviewer))->toBeNull();

    app(TaskWorkflow::class)->approveTask($firstReviewTask);

    $secondReviewTask = $claimTask->handle($project, AgentRole::Reviewer);

    expect($firstTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($secondReviewTask?->id)->toBe($secondTask->id);

    app(TaskWorkflow::class)->approveTask($secondReviewTask);

    expect($secondTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($thirdTask->id);
});

test('a same-phase dependency is satisfied once its dependency reaches ready for review', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $firstTask->update(['phase_id' => $phase->id]);
    $secondTask->update(['phase_id' => $phase->id]);
    $secondTask->dependencies()->attach($firstTask);

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($firstTask->id);

    $transitionTask->handle($firstTask, TaskStatus::Validating);
    $transitionTask->handle($firstTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Reviewer))->toBeNull()
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);
});

test('a cancelled task counts as having crossed the phase review barrier', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $firstTask->update(['phase_id' => $phase->id]);
    $secondTask->update(['phase_id' => $phase->id]);

    $transitionTask = app(TransitionTask::class);
    $transitionTask->handle($firstTask, TaskStatus::Coding);
    $transitionTask->handle($firstTask, TaskStatus::Validating);
    $transitionTask->handle($firstTask, TaskStatus::ReadyForReview);
    $transitionTask->handle($secondTask, TaskStatus::Blocked);
    $transitionTask->handle($secondTask, TaskStatus::Cancelled);

    $claimTask = app(ClaimTask::class);

    expect($claimTask->handle($project, AgentRole::Reviewer)?->id)->toBe($firstTask->id);
});

test('a cancelled task lets the coder advance into the next phase', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $firstPhase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);
    $secondPhase = Phase::create(['project_id' => $project->id, 'position' => 2, 'title' => 'Delivery', 'objective' => 'Deliver the feature.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $firstTask->update(['phase_id' => $firstPhase->id]);
    $secondTask->update(['phase_id' => $secondPhase->id]);

    $transitionTask = app(TransitionTask::class);
    $transitionTask->handle($firstTask, TaskStatus::Blocked);
    $transitionTask->handle($firstTask, TaskStatus::Cancelled);

    $claimTask = app(ClaimTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);
});

test('a task depending on a cancelled task remains claimable by the coder', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $firstTask->update(['phase_id' => $phase->id]);
    $secondTask->update(['phase_id' => $phase->id]);
    $secondTask->dependencies()->attach($firstTask);

    $transitionTask = app(TransitionTask::class);
    $transitionTask->handle($firstTask, TaskStatus::Blocked);
    $transitionTask->handle($firstTask, TaskStatus::Cancelled);

    $claimTask = app(ClaimTask::class);

    expect($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);
});

test('changes required closes the phase review gate and returns control to the coder', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);
    $thirdTask = createWorkflowTask($project, 3);

    foreach ([$firstTask, $secondTask, $thirdTask] as $task) {
        $task->update([
            'phase_id' => $phase->id,
            'status' => TaskStatus::ReadyForReview,
        ]);
    }

    $claimTask = app(ClaimTask::class);
    $transitionTask = app(TransitionTask::class);

    $firstReviewTask = $claimTask->handle($project, AgentRole::Reviewer);
    app(TaskWorkflow::class)->approveTask($firstReviewTask);

    $secondReviewTask = $claimTask->handle($project, AgentRole::Reviewer);

    expect($secondReviewTask?->id)->toBe($secondTask->id);

    $transitionTask->handle($secondReviewTask, TaskStatus::ChangesRequired);

    expect($thirdTask->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and($claimTask->handle($project, AgentRole::Reviewer))->toBeNull()
        ->and($claimTask->handle($project, AgentRole::Coder)?->id)->toBe($secondTask->id);

    $transitionTask->handle($secondTask, TaskStatus::Validating);
    $transitionTask->handle($secondTask, TaskStatus::ReadyForReview);

    expect($claimTask->handle($project, AgentRole::Reviewer)?->id)->toBe($secondTask->id);
});

test('reviewer worker cooldown blocks the next phase review claim for 300 seconds', function () {
    config()->set('aios.worker_task_cooldown_seconds', 300);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Build the foundation.']);

    foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
        AgentWorker::create([
            'project_id' => $project->id,
            'role' => $role,
            'status' => 'idle',
        ]);
    }

    $firstTask = createWorkflowTask($project, 1);
    $secondTask = createWorkflowTask($project, 2);

    foreach ([$firstTask, $secondTask] as $task) {
        $task->update([
            'phase_id' => $phase->id,
            'status' => TaskStatus::ReadyForReview,
        ]);

        TaskAttempt::create([
            'task_id' => $task->id,
            'number' => 1,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    $review = [
        'outcome' => 'approved',
        'summary' => 'All acceptance criteria are met.',
        'findings' => [],
    ];

    Process::fake([
        '*' => Process::result(
            output: json_encode([
                'type' => 'item.completed',
                'item' => [
                    'type' => 'agent_message',
                    'text' => json_encode($review, JSON_THROW_ON_ERROR),
                ],
            ], JSON_THROW_ON_ERROR),
        ),
    ]);

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($firstTask->refresh()->status)->toBe(TaskStatus::Done)
        ->and($secondTask->refresh()->status)->toBe(TaskStatus::ReadyForReview);

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($secondTask->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview, 'the reviewer must remain on its configured 300-second cooldown');

    $this->travel(301)->seconds();

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($secondTask->refresh()->status)->toBe(TaskStatus::Done);
});

test('Project Manager roadmap retry cooldown prevents immediate failed roadmap reclaim', function () {
    config()->set('aios.roadmap_retry_cooldown_seconds', 3600);
    config()->set('aios.max_roadmap_attempts', 3);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create([
        'name' => 'Roadmap cooldown',
        'path' => '/tmp/roadmap-cooldown-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
        AgentWorker::create([
            'project_id' => $project->id,
            'role' => $role,
            'status' => 'idle',
        ]);
    }

    $worker = AgentWorker::query()
        ->whereBelongsTo($project)
        ->where('role', AgentRole::ProjectManager)
        ->firstOrFail();

    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/cooldown.md',
        'status' => 'uploaded',
        'content' => 'Produce a valid implementation roadmap.',
    ]);

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->twice()
        ->andReturn([
            'exit_code' => 0,
            'output' => 'This is not valid structured roadmap output.',
            'error_output' => '',
        ]);

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($roadmap->attempts()->count())->toBe(1)
        ->and($worker->refresh()->task_completed_at)->not->toBeNull();

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($roadmap->attempts()->count())->toBe(1);

    $this->travel(3601)->seconds();

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($roadmap->attempts()->count())->toBe(2);
});

test('Project Manager roadmap retries become blocked at the configured attempt limit', function () {
    config()->set('aios.roadmap_retry_cooldown_seconds', 0);
    config()->set('aios.max_roadmap_attempts', 2);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create([
        'name' => 'Roadmap retry limit',
        'path' => '/tmp/roadmap-retry-limit-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
        AgentWorker::create([
            'project_id' => $project->id,
            'role' => $role,
            'status' => 'idle',
        ]);
    }

    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/retry-limit.md',
        'status' => 'uploaded',
        'content' => 'Produce a valid implementation roadmap.',
    ]);

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->twice()
        ->andReturn([
            'exit_code' => 0,
            'output' => 'This is not valid structured roadmap output.',
            'error_output' => '',
        ]);

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($roadmap->attempts()->count())->toBe(1);

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('blocked')
        ->and($roadmap->attempts()->count())->toBe(2)
        ->and($project->auditEvents()->where('event_type', 'roadmap.retry_exhausted')->exists())->toBeTrue();

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('blocked')
        ->and($roadmap->attempts()->count())->toBe(2);
});

test('an already exhausted failed roadmap is blocked before another Project Manager execution', function () {
    config()->set('aios.roadmap_retry_cooldown_seconds', 0);
    config()->set('aios.max_roadmap_attempts', 2);

    $project = Project::create([
        'name' => 'Existing exhausted roadmap',
        'path' => '/tmp/exhausted-roadmap-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
        AgentWorker::create([
            'project_id' => $project->id,
            'role' => $role,
            'status' => 'idle',
        ]);
    }

    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/exhausted.md',
        'status' => 'failed',
        'content' => 'Produce a valid implementation roadmap.',
    ]);

    foreach ([1, 2] as $number) {
        RoadmapAttempt::create([
            'roadmap_id' => $roadmap->id,
            'number' => $number,
            'status' => 'failed',
            'claimed_at' => now()->subMinutes(10 - $number),
            'finished_at' => now()->subMinutes(9 - $number),
        ]);
    }

    mock(CodexCliRunner::class)->shouldNotReceive('run');

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('blocked')
        ->and($roadmap->attempts()->count())->toBe(2)
        ->and($project->auditEvents()->where('event_type', 'roadmap.retry_exhausted')->exists())->toBeTrue();
});

/**
 * Coder/Reviewer share the ProjectManager's AgentWorker.task_completed_at-based cooldown
 * mechanism (see RunAiosWorkers::onTaskCooldown). Roadmap-batching tests create real Tasks and,
 * within the same `aios:work --once` cycle, would otherwise have the Coder/Reviewer loop
 * immediately claim and run them too via the same shared CodexCliRunner fallback mock — pin
 * Coder/Reviewer onto their cooldown up front so only the Project Manager claims are exercised.
 */
function createRoadmapBatchTestWorkers(Project $project): void
{
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::ProjectManager, 'status' => 'idle']);
    foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
        AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => 'idle', 'task_completed_at' => now()]);
    }
}

/** @return array<string, mixed> */
function roadmapBatchPlan(string $phaseTitle, string $taskTitle, bool $remainingWork): array
{
    return [
        'phases' => [[
            'title' => $phaseTitle,
            'objective' => 'Batch phase.',
            'tasks' => [
                ['title' => $taskTitle, 'objective' => 'Do it.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => []],
            ],
        ]],
        'remaining_work' => $remainingWork,
    ];
}

test('a roadmap larger than the batch cap persists only the capped phases and stays in progress', function () {
    config()->set('aios.roadmap_max_phases_per_batch', 2);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    Queue::fake();

    $project = Project::create(['name' => 'Batch decomposition', 'path' => '/tmp/batch-decomposition-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/batch.md', 'status' => 'uploaded', 'content' => 'Build a large application.']);

    $plan = [
        'phases' => [
            ['title' => 'Phase 1', 'objective' => 'First.', 'tasks' => [['title' => 'Task 1', 'objective' => 'Do it.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => []]]],
            ['title' => 'Phase 2', 'objective' => 'Second.', 'tasks' => [['title' => 'Task 2', 'objective' => 'Do it.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => []]]],
            ['title' => 'Phase 3', 'objective' => 'Third.', 'tasks' => [['title' => 'Task 3', 'objective' => 'Do it.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => []]]],
        ],
        'remaining_work' => true,
    ];

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn(['exit_code' => 0, 'output' => json_encode($plan, JSON_THROW_ON_ERROR), 'error_output' => '']);

    app(RunProjectManager::class)->handle($roadmap);

    expect($roadmap->refresh()->status)->toBe('in_progress')
        ->and($roadmap->attempts()->count())->toBe(1)
        ->and($roadmap->attempts()->first()->status)->toBe('persisted_partial')
        ->and($project->phases()->count())->toBe(2)
        ->and($project->tasks()->count())->toBe(2)
        ->and($project->tasks()->orderBy('position')->pluck('title')->all())->toBe(['Task 1', 'Task 2']);

    Queue::assertPushed(ProcessRoadmap::class, fn (ProcessRoadmap $job): bool => $job->roadmapId === $roadmap->id);
});

test('an in progress roadmap batch continues immediately without waiting for the roadmap cooldown', function () {
    config()->set('aios.roadmap_retry_cooldown_seconds', 3600);
    config()->set('aios.roadmap_max_phases_per_batch', 1);
    config()->set('aios.max_roadmap_attempts', 3);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create([
        'name' => 'Roadmap batching continuation',
        'path' => '/tmp/roadmap-batch-continuation-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    createRoadmapBatchTestWorkers($project);

    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/batch-continuation.md',
        'status' => 'uploaded',
        'content' => 'Produce a large implementation roadmap.',
    ]);

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->andReturn(
            ['exit_code' => 0, 'output' => json_encode(roadmapBatchPlan('Phase 1', 'Task 1', true), JSON_THROW_ON_ERROR), 'error_output' => ''],
            ['exit_code' => 0, 'output' => json_encode(roadmapBatchPlan('Phase 2', 'Task 2', false), JSON_THROW_ON_ERROR), 'error_output' => ''],
        );

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('in_progress')
        ->and($roadmap->attempts()->count())->toBe(1)
        ->and($project->phases()->count())->toBe(1);

    // No time travel: an in-progress roadmap must not wait out roadmap_retry_cooldown_seconds
    // between batches, unlike a fresh failed-roadmap retry.
    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($roadmap->refresh()->status)->toBe('processed')
        ->and($roadmap->attempts()->count())->toBe(2)
        ->and($project->phases()->count())->toBe(2)
        ->and($project->tasks()->count())->toBe(2)
        ->and($project->tasks()->orderBy('position')->pluck('position')->all())->toBe([1, 2]);
});

test('a later batch may declare a dependency on a task from an earlier batch', function () {
    // Regression: validatePlan's forward-dependency check restarted its position counter at 0
    // for every batch, so a legitimate depends_on reference to an earlier-batch task (whose real
    // global position is higher than this batch's local counter) was wrongly rejected as
    // "depends on a later task", failing the whole batch with invalid_plan.
    config()->set('aios.roadmap_retry_cooldown_seconds', 3600);
    config()->set('aios.roadmap_max_phases_per_batch', 1);
    config()->set('aios.max_roadmap_attempts', 3);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create([
        'name' => 'Roadmap cross batch dependency',
        'path' => '/tmp/roadmap-cross-batch-dependency-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    createRoadmapBatchTestWorkers($project);

    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/cross-batch-dependency.md',
        'status' => 'uploaded',
        'content' => 'Produce a large implementation roadmap.',
    ]);

    $firstBatch = roadmapBatchPlan('Phase 1', 'Task 1', true);
    $secondBatch = [
        'phases' => [[
            'title' => 'Phase 2',
            'objective' => 'Batch phase.',
            'tasks' => [
                ['title' => 'Task 2', 'objective' => 'Do it.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => [1]],
            ],
        ]],
        'remaining_work' => false,
    ];

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->andReturn(
            ['exit_code' => 0, 'output' => json_encode($firstBatch, JSON_THROW_ON_ERROR), 'error_output' => ''],
            ['exit_code' => 0, 'output' => json_encode($secondBatch, JSON_THROW_ON_ERROR), 'error_output' => ''],
        );

    $this->artisan('aios:work --once')->assertExitCode(0);
    $this->artisan('aios:work --once')->assertExitCode(0);

    $tasks = $project->tasks()->orderBy('position')->get();

    expect($roadmap->refresh()->status)->toBe('processed')
        ->and($tasks)->toHaveCount(2)
        ->and($tasks[1]->dependencies()->pluck('position')->all())->toBe([1]);
});

test('a roadmap batch failure after successful earlier batches does not exhaust the retry budget prematurely', function () {
    config()->set('aios.roadmap_retry_cooldown_seconds', 0);
    config()->set('aios.roadmap_max_phases_per_batch', 1);
    config()->set('aios.max_roadmap_attempts', 2);
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create([
        'name' => 'Roadmap batch retry budget',
        'path' => '/tmp/roadmap-batch-retry-budget-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    createRoadmapBatchTestWorkers($project);

    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/batch-retry-budget.md',
        'status' => 'uploaded',
        'content' => 'Produce a large implementation roadmap.',
    ]);

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->andReturn(
            ['exit_code' => 0, 'output' => json_encode(roadmapBatchPlan('Phase 1', 'Task 1', true), JSON_THROW_ON_ERROR), 'error_output' => ''],
            ['exit_code' => 1, 'output' => '', 'error_output' => 'harness crashed'],
            ['exit_code' => 1, 'output' => '', 'error_output' => 'harness crashed'],
        );

    // Batch 1 succeeds and leaves the roadmap in_progress; it must not consume retry budget.
    $this->artisan('aios:work --once')->assertExitCode(0);
    expect($roadmap->refresh()->status)->toBe('in_progress');

    // Two real failures on the next batch still take the full configured retry budget (2) to block.
    $this->artisan('aios:work --once')->assertExitCode(0);
    expect($roadmap->refresh()->status)->toBe('failed');

    $this->artisan('aios:work --once')->assertExitCode(0);
    expect($roadmap->refresh()->status)->toBe('blocked')
        ->and($roadmap->attempts()->count())->toBe(3);
});
