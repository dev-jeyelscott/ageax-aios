<?php

use App\Actions\RunCoderTask;
use App\Actions\RunProjectManager;
use App\Actions\RunReviewerTask;
use App\AgentHarness;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function boundAgentProject(AgentRole $role, bool $enabled = true): array
{
    $project = Project::create(['name' => 'Bound', 'path' => '/tmp/aios-binding-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $agent = Agent::factory()->for($project)->create(['role' => $role, 'harness' => AgentHarness::Codex, 'enabled' => $enabled]);
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'agent_id' => $agent->id, 'status' => 'idle']);

    return [$project, $agent, $worker];
}

test('a disabled Coder Agent blocks the task instead of falling back to legacy execution', function () {
    [$project] = boundAgentProject(AgentRole::Coder, enabled: false);
    File::ensureDirectoryExists($project->path);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Coding task',
        'objective' => 'Attempt implementation.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
    Process::fake();

    app(RunCoderTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->attempts()->value('status'))->toBe('blocked')
        ->and($task->attempts()->value('validation_results'))->toMatchArray(['checks' => ['agent_binding' => false]])
        ->and($task->auditEvents()->where('event_type', 'task.blocked_agent_misconfigured')->exists())->toBeTrue()
        ->and(AgentRun::query()->whereBelongsTo($task)->exists())->toBeFalse();
    Process::assertNotRan(fn (): bool => true);
});

test('a disabled bound Agent blocks Project Manager processing instead of falling back to legacy execution', function () {
    [$project] = boundAgentProject(AgentRole::ProjectManager, enabled: false);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/example.md', 'status' => 'uploaded', 'content' => 'Implement the task.']);
    Process::fake();

    app(RunProjectManager::class)->handle($roadmap);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($project->auditEvents()->where('event_type', 'roadmap.blocked_agent_misconfigured')->exists())->toBeTrue()
        ->and($project->tasks()->count())->toBe(0);
    Process::assertNotRan(fn (): bool => true);
});

test('a misconfigured harness identifier blocks Reviewer processing as a bounded operational failure', function () {
    [$project, $agent] = boundAgentProject(AgentRole::Reviewer);
    DB::table('agents')->where('id', $agent->id)->update(['harness' => 'unsupported_harness']);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Reviewable task',
        'objective' => 'Verify the implementation.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Reviewing,
    ]);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    Process::fake();

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and($task->reviews()->count())->toBe(0)
        ->and($task->auditEvents()->where('event_type', 'review.failed')->exists())->toBeTrue()
        ->and(AgentRun::query()->whereBelongsTo($task)->exists())->toBeFalse();
    Process::assertNotRan(fn (): bool => true);

    config()->set('aios.max_reviewer_attempts', 1);
    $secondAttempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 2, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    $task->update(['status' => TaskStatus::Reviewing]);

    app(RunReviewerTask::class)->run($task, $secondAttempt);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->auditEvents()->where('event_type', 'review.retry_exhausted')->exists())->toBeTrue();
});

test('an unbound workflow role keeps using the legacy execution path', function () {
    $project = Project::create(['name' => 'Legacy', 'path' => '/tmp/aios-binding-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    File::ensureDirectoryExists($project->path);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Coding task',
        'objective' => 'Attempt implementation.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'Codex failed.')]);

    app(RunCoderTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->auditEvents()->where('event_type', 'task.blocked_agent_misconfigured')->exists())->toBeFalse();
});
