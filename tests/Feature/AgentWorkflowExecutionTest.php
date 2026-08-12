<?php

use App\Actions\ApplyRoadmapPlan;
use App\Actions\ClaimTask;
use App\Actions\RunCoderTask;
use App\Actions\RunProjectManager;
use App\Actions\RunReviewerTask;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use App\Services\StaleWorkerRecovery;
use App\Services\TaskCommitter;
use App\Services\TaskValidator;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

use function Pest\Laravel\mock;

function reviewTask(Project $project): Task
{
    return Task::create([
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
}

test('the task validator allows the committed environment template', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    File::ensureDirectoryExists($project->path);
    $task = reviewTask($project);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result(output: '?? .env.example')),
    ]);

    $validation = app(TaskValidator::class)->validate($task);

    expect($validation['passed'])->toBeTrue()
        ->and($validation['checks']['forbidden_file_check'])->toBeTrue();
});

test('the task validator blocks environment files that may contain secrets', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    File::ensureDirectoryExists($project->path);
    $task = reviewTask($project);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result(output: '?? .env.local')),
    ]);

    $validation = app(TaskValidator::class)->validate($task);

    expect($validation['passed'])->toBeFalse()
        ->and($validation['checks']['forbidden_file_check'])->toBeFalse();
});

test('the task validator runs safe task-specific verification commands', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = reviewTask($project);
    $task->update(['verification_commands' => ['php artisan test --compact']]);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result())
        ->push(Process::result()),
    ]);

    $validation = app(TaskValidator::class)->validate($task);

    expect($validation['passed'])->toBeTrue()
        ->and($validation['checks']['task_verification'])->toBeTrue();
});

test('the task validator rejects unsafe verification commands without executing them', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = reviewTask($project);
    $task->update(['verification_commands' => ['php artisan test; rm -rf /']]);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result()),
    ]);

    $validation = app(TaskValidator::class)->validate($task);

    expect($validation['passed'])->toBeFalse()
        ->and($validation['checks']['task_verification'])->toBeFalse();
});

test('the task committer excludes files that existed before the agent attempt', function () {
    $path = '/tmp/aios-commit-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/baseline.txt', 'baseline');
    Process::path($path)->run(['git', 'add', 'baseline.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);
    File::put($path.'/unrelated.txt', 'keep out of the task commit');
    File::put($path.'/task.txt', 'created by the task');
    $project = Project::create(['name' => 'Example', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'dirty']);
    $task = reviewTask($project);

    $commit = app(TaskCommitter::class)->commit($task, ['task.txt']);
    $committedFiles = Process::path($path)->run(['git', 'show', '--format=', '--name-only', 'HEAD']);
    $status = Process::path($path)->run(['git', 'status', '--porcelain']);

    expect($commit)->not->toBeNull()
        ->and($committedFiles->output())->toContain('task.txt')
        ->and($committedFiles->output())->not->toContain('unrelated.txt')
        ->and($status->output())->toContain('unrelated.txt')
        ->and($project->refresh()->git_head_sha)->toBe($commit)
        ->and($project->git_status)->toBe('dirty');
});

test('reviewer persists actionable findings and returns a task to the coder', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = reviewTask($project);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    $review = ['outcome' => 'changes_required', 'summary' => 'A required check is missing.', 'findings' => [[
        'severity' => 'high',
        'location' => 'app/Example.php',
        'current_implementation' => 'The check is absent.',
        'expected_implementation' => 'The check is present.',
        'why_incorrect' => 'The acceptance criterion is unmet.',
        'required_fix' => 'Add the check.',
        'verification_requirement' => 'Run the focused test.',
        'implementation_fix_context' => 'Preserve existing behavior.',
    ]]];
    Process::fake(['*' => Process::result(output: json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($review, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR))]);

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and($task->reviews)->toHaveCount(1)
        ->and($task->reviews->first()->findings)->toHaveCount(1)
        ->and(File::get($vault.'/Projects/example/Reviews/TASK-001.md'))->toContain('Required fix: Add the check.')
        ->and($task->auditEvents()->where('event_type', 'review.started')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'review.completed')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'review.finding_recorded')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.rejected')->exists())->toBeTrue();
});

test('a reviewer without a valid decision returns the task to the coder instead of retrying review', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = reviewTask($project);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    Process::fake(['*' => Process::result(output: 'Reviewer could not complete the decision.')]);

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and($task->auditEvents()->where('event_type', 'review.failed')->exists())->toBeTrue()
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder)?->id)->toBe($task->id);
});

test('a reviewer execution exception returns the task to the coder with durable failed evidence', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = reviewTask($project);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    mock(CodexCliRunner::class)->shouldReceive('run')->once()->andThrow(new RuntimeException('The process ended unexpectedly.'));

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and(AgentRun::query()->whereBelongsTo($task)->value('exit_code'))->toBe(-1)
        ->and($task->auditEvents()->where('event_type', 'review.failed')->exists())->toBeTrue()
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder)?->id)->toBe($task->id);
});

test('live reviewer output refreshes the worker heartbeat', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Reviewer, 'status' => 'working', 'last_heartbeat_at' => now()->subMinutes(5)]);
    $task = reviewTask($project);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (Project $runProject, string $prompt, Closure $onOutput): array {
            $onOutput('stdout', 'Reviewer is inspecting the implementation.');

            return ['exit_code' => 1, 'output' => '', 'error_output' => 'The reviewer stopped.'];
        });

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($worker->refresh()->last_heartbeat_at?->isAfter(now()->subMinute()))->toBeTrue()
        ->and($worker->status)->toBe('working');
});

test('the reviewer receives the complete task capsule and implementation evidence', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $phase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Ship the foundation.']);
    $firstTask = Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'TASK-000',
        'position' => 0,
        'title' => 'Foundation task',
        'objective' => 'Prepare the foundation.',
        'acceptance_criteria' => ['It is prepared.'],
        'implementation_prompt' => 'Prepare it.',
        'context_capsule' => [],
        'status' => TaskStatus::ReadyForReview,
    ]);
    TaskAttempt::create(['task_id' => $firstTask->id, 'number' => 1, 'commit_sha' => 'foundation-commit', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    $task = reviewTask($project);
    $task->update(['phase_id' => $phase->id]);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'base_sha' => 'base-sha', 'head_sha' => 'head-sha', 'commit_sha' => 'commit-sha', 'status' => 'completed', 'validation_results' => ['git_diff_check' => true], 'changed_files' => ['app/Example.php'], 'started_at' => now(), 'finished_at' => now()]);
    $review = ['outcome' => 'approved', 'summary' => 'Implemented and verified the task.', 'findings' => []];
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->withArgs(function (Project $runProject, string $prompt, mixed $callback) use ($project): bool {
            return $runProject->is($project)
                && $callback instanceof Closure
                && str_contains($prompt, '"implementation_prompt":"Implement it."')
                && str_contains($prompt, '"commit_sha":"commit-sha"')
                && str_contains($prompt, '"changed_files":["app\\/Example.php"]')
                && str_contains($prompt, '"title":"Foundation"')
                && str_contains($prompt, '"commit_sha":"foundation-commit"');
        })
        ->andReturn(['exit_code' => 0, 'output' => json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($review, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR), 'error_output' => '']);

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)->toBe(TaskStatus::Done)
        ->and($firstTask->refresh()->status)->toBe(TaskStatus::Done);
});

test('roadmap tasks have deterministic serial dependencies', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);

    app(ApplyRoadmapPlan::class)->handle($project, [[
        'title' => 'Phase One',
        'objective' => 'Ship a thin slice.',
        'tasks' => [
            ['title' => 'First', 'objective' => 'Do first.', 'acceptance_criteria' => ['First passes.'], 'scope' => ['workflow'], 'constraints' => ['Keep the public API stable.'], 'relevant_paths' => ['app/Services'], 'verification_commands' => ['php artisan test --compact'], 'implementation_prompt' => 'Do first.'],
            ['title' => 'Second', 'objective' => 'Do second.', 'acceptance_criteria' => ['Second passes.'], 'implementation_prompt' => 'Do second.'],
        ],
    ]]);

    $tasks = $project->tasks()->orderBy('position')->get();

    expect($tasks)->toHaveCount(2)
        ->and($tasks[1]->dependencies()->pluck('tasks.id')->all())->toBe([$tasks[0]->id])
        ->and($tasks[0]->scope)->toBe(['workflow'])
        ->and($tasks[0]->constraints)->toBe(['Keep the public API stable.'])
        ->and($tasks[0]->relevant_paths)->toBe(['app/Services'])
        ->and($tasks[0]->verification_commands)->toBe(['php artisan test --compact'])
        ->and($project->auditEvents()->where('event_type', 'phase.created')->count())->toBe(1)
        ->and($project->auditEvents()->where('event_type', 'task.created')->count())->toBe(2);
});

test('roadmap tasks evidenced as complete are skipped by the coder', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);

    app(ApplyRoadmapPlan::class)->handle($project, [[
        'title' => 'Phase One',
        'objective' => 'Ship a thin slice.',
        'tasks' => [
            ['title' => 'Already complete', 'objective' => 'Do first.', 'acceptance_criteria' => ['First passes.'], 'implementation_prompt' => 'Do first.', 'completion_status' => 'done', 'completion_evidence' => 'Verified in app/ExistingFeature.php by the existing feature test.'],
            ['title' => 'Still needed', 'objective' => 'Do second.', 'acceptance_criteria' => ['Second passes.'], 'implementation_prompt' => 'Do second.', 'completion_status' => 'queued', 'completion_evidence' => null],
        ],
    ]]);

    $tasks = $project->tasks()->orderBy('position')->get();
    $claimed = app(ClaimTask::class)->handle($project, AgentRole::Coder);

    expect($tasks[0]->status)->toBe(TaskStatus::Done)
        ->and($tasks[0]->completed_at)->not->toBeNull()
        ->and($tasks[0]->auditEvents()->where('event_type', 'task.imported_completed')->exists())->toBeTrue()
        ->and(File::get($vault.'/Projects/example/Tasks/TASK-001 - already-complete.md'))->toContain('Verified in app/ExistingFeature.php by the existing feature test.')
        ->and($claimed?->id)->toBe($tasks[1]->id);
});

test('the worker applies a reviewer decision during a polling cycle', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    foreach (AgentRole::cases() as $role) {
        AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => 'idle']);
    }
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Reviewable task',
        'objective' => 'Verify the implementation.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::ReadyForReview,
    ]);
    TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
    $review = ['outcome' => 'approved', 'summary' => 'All acceptance criteria are met.', 'findings' => []];
    Process::fake(['*' => Process::result(output: json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($review, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR))]);

    $this->artisan('aios:work --once')->assertExitCode(0);

    $taskNote = $vault.'/Projects/example/Tasks/TASK-001 - reviewable-task.md';

    expect($task->refresh()->status)->toBe(TaskStatus::Done)
        ->and(File::get($taskNote))->toContain('All acceptance criteria are met.')
        ->and(File::get($taskNote))->toContain('## Implementation summary')
        ->and($task->auditEvents()->where('event_type', 'review.completed')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.approved')->exists())->toBeTrue();
});

test('the worker recovers stale runs before polling for new work', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    foreach (AgentRole::cases() as $role) {
        AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => 'idle']);
    }
    mock(StaleWorkerRecovery::class)
        ->shouldReceive('recover')
        ->once()
        ->withArgs(fn (Project $recoveryProject): bool => $recoveryProject->is($project));

    $this->artisan('aios:work --once')->assertExitCode(0);
});

test('a failed coder execution is persisted and becomes eligible for a fresh attempt', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
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
        ->and($task->attempts()->value('status'))->toBe('failed')
        ->and(AgentRun::query()->whereBelongsTo($task)->value('exit_code'))->toBe(1)
        ->and($task->auditEvents()->where('event_type', 'task.validated')->exists())->toBeTrue();
});

test('an unsafe persisted project path blocks coding before any process starts', function () {
    $project = Project::create(['name' => 'Unsafe', 'path' => base_path(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
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
        ->and($task->attempts()->value('validation_results'))->toMatchArray(['checks' => ['workspace_path' => false]])
        ->and($task->auditEvents()->where('event_type', 'task.blocked_unsafe_path')->exists())->toBeTrue();
    Process::assertNotRan(fn (): bool => true);
});

test('a coder exception leaves durable execution evidence for recovery', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
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
    mock(CodexCliRunner::class)->shouldReceive('run')->once()->andThrow(new RuntimeException('The process ended unexpectedly.'));

    app(RunCoderTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and(AgentRun::query()->whereBelongsTo($task)->value('status'))->toBe(AgentRunStatus::Failed)
        ->and(AgentRun::query()->whereBelongsTo($task)->value('exit_code'))->toBe(-1);
});

test('coder retries become blocked at the configured attempt limit', function () {
    config()->set('aios.max_coder_attempts', 1);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
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

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked);
});

test('stale coder recovery interrupts the run and makes the same task retryable', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => 'working', 'last_heartbeat_at' => now()->subMinutes(5)]);
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
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(5)]);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'test'), 'started_at' => now()->subMinutes(5)]);

    app(StaleWorkerRecovery::class)->recover($project, 60);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($attempt->refresh()->status)->toBe('interrupted')
        ->and($run->refresh()->status)->toBe(AgentRunStatus::Interrupted);
});

test('stale recovery preserves repository and execution evidence for the fresh retry context', function () {
    $path = sys_get_temp_dir().'/recovery-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    $project = Project::create(['name' => 'Example', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => 'working', 'last_heartbeat_at' => now()->subMinutes(5)]);
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
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'base_sha' => 'base-sha', 'status' => 'running', 'validation_results' => ['baseline_changed_files' => ['unrelated.txt']], 'started_at' => now()->subMinutes(5)]);
    AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'test'), 'result' => ['agent_messages' => ['Interrupted while editing.']], 'log_path' => 'agent-runs/interrupted.jsonl', 'started_at' => now()->subMinutes(5)]);
    Process::fake(['*' => Process::sequence([
        Process::result(output: 'current-sha'),
        Process::result(output: ' M app/Example.php'),
        Process::result(output: ' app/Example.php | 2 +-'),
    ])]);

    app(StaleWorkerRecovery::class)->recover($project, 60);

    $recovery = $attempt->refresh()->validation_results['recovery'];

    expect($recovery)->toMatchArray([
        'base_sha' => 'base-sha',
        'current_head_sha' => 'current-sha',
        'working_tree' => 'M app/Example.php',
        'diff_stat' => 'app/Example.php | 2 +-',
    ]);
    expect($recovery['previous_attempt'])->toMatchArray(['number' => 1, 'status' => 'running']);
    expect($recovery['previous_run'])->toMatchArray(['log_path' => 'agent-runs/interrupted.jsonl']);
    $recoveryAuditPayload = $task->auditEvents()->where('event_type', 'task.recovered')->firstOrFail()->payload;
    expect($recoveryAuditPayload)->toHaveKey('evidence');
    expect($recoveryAuditPayload['evidence']['current_head_sha'])->toBe('current-sha');
});

test('stale reviewer recovery returns the task to the coder', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Reviewer, 'status' => 'working', 'last_heartbeat_at' => now()->subMinutes(5)]);
    $task = reviewTask($project);
    AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Reviewer, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'test'), 'started_at' => now()->subMinutes(5)]);

    app(StaleWorkerRecovery::class)->recover($project, 60);

    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired)
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder)?->id)->toBe($task->id);
});

test('stale recovery safely ignores the global knowledge architect role', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::KnowledgeArchitect, 'status' => 'working', 'last_heartbeat_at' => now()->subMinutes(5)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(1)
        ->and($worker->refresh()->status)->toBe('interrupted');
});

test('the core roadmap workflow survives a reviewer rejection and completes the next eligible task only after approval', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $path = '/tmp/aios-core-workflow-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/README.md', '# Core workflow');
    Process::path($path)->run(['git', 'add', 'README.md']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);
    $project = Project::create(['name' => 'Example', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
        AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => 'idle']);
    }
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/example.md', 'status' => 'uploaded', 'content' => 'Implement the single focused task.']);
    $plan = ['project_knowledge' => ['overview' => 'A focused workflow proof.'], 'phases' => [[
        'title' => 'Foundation',
        'objective' => 'Deliver the task.',
        'tasks' => [[
            'title' => 'Implement the focused task',
            'objective' => 'Write the verified feature file.',
            'acceptance_criteria' => ['The feature file contains the approved implementation.'],
            'scope' => ['feature.txt'],
            'constraints' => ['Keep the change focused.'],
            'relevant_paths' => ['feature.txt'],
            'verification_commands' => [],
            'implementation_prompt' => 'Create feature.txt with the correct implementation.',
            'completion_status' => 'queued',
            'completion_evidence' => null,
        ]],
    ]]];
    $reviews = [
        ['outcome' => 'changes_required', 'summary' => 'The initial implementation needs the approved content.', 'findings' => [[
            'severity' => 'high',
            'location' => 'feature.txt',
            'current_implementation' => 'The file contains the first draft.',
            'expected_implementation' => 'The file contains the approved implementation.',
            'why_incorrect' => 'The review criterion is not met.',
            'required_fix' => 'Update the file to the approved content.',
            'verification_requirement' => 'Inspect feature.txt.',
            'implementation_fix_context' => 'Keep the change limited to the task.',
        ]]],
        ['outcome' => 'approved', 'summary' => 'feature.txt now contains the approved implementation and validation passed.', 'findings' => []],
    ];
    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->times(5)
        ->andReturnUsing(function (Project $runProject, string $prompt, mixed $onOutput) use ($path, $plan, &$reviews): array {
            if (str_contains($prompt, 'You are the Project Manager.')) {
                return ['exit_code' => 0, 'output' => json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($plan, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR), 'error_output' => ''];
            }

            if (str_contains($prompt, 'You are the Coder role.')) {
                File::put($path.'/feature.txt', File::exists($path.'/feature.txt') ? 'approved implementation' : 'first draft');

                return ['exit_code' => 0, 'output' => json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => '{"summary":"Implemented the focused task."}']], JSON_THROW_ON_ERROR), 'error_output' => ''];
            }

            $review = array_shift($reviews);

            return ['exit_code' => 0, 'output' => json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($review, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR), 'error_output' => ''];
        });

    app(RunProjectManager::class)->handle($roadmap);
    $task = $project->tasks()->sole();
    expect(app(ClaimTask::class)->handle($project, AgentRole::Coder)?->id)->toBe($task->id);
    app(RunCoderTask::class)->handle($task->refresh());
    expect($task->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder))->toBeNull();
    $reviewTask = app(ClaimTask::class)->handle($project, AgentRole::Reviewer);
    app(RunReviewerTask::class)->run($reviewTask, $reviewTask->attempts()->latest('number')->firstOrFail());
    expect($task->refresh()->status)->toBe(TaskStatus::ChangesRequired);
    $retryTask = app(ClaimTask::class)->handle($project, AgentRole::Coder);
    expect($retryTask)->not->toBeNull();
    app(RunCoderTask::class)->handle($retryTask);
    expect($task->attempts()->latest('number')->value('validation_results'))->toMatchArray(['passed' => true]);
    expect($task->attempts()->latest('number')->value('changed_files'))->toBe(['feature.txt']);
    expect(File::get($path.'/feature.txt'))->toBe('approved implementation');
    expect($task->refresh()->status)->toBe(TaskStatus::ReadyForReview);
    $approvalTask = app(ClaimTask::class)->handle($project, AgentRole::Reviewer);
    expect($approvalTask)->not->toBeNull();
    app(RunReviewerTask::class)->run($approvalTask, $approvalTask->attempts()->latest('number')->firstOrFail());

    expect($roadmap->refresh()->status)->toBe('processed')
        ->and($task->refresh()->status)->toBe(TaskStatus::Done)
        ->and($task->attempts)->toHaveCount(2)
        ->and($task->reviews)->toHaveCount(2)
        ->and(File::get($path.'/feature.txt'))->toBe('approved implementation')
        ->and($project->refresh()->git_head_sha)->toBe($task->attempts()->latest('number')->value('commit_sha'))
        ->and($project->git_status)->toBe('clean')
        ->and(File::get($vault.'/Projects/example/Tasks/TASK-001 - implement-the-focused-task.md'))->toContain('approved implementation')
        ->and($project->auditEvents()->where('event_type', 'roadmap.processed')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.rejected')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.approved')->exists())->toBeTrue();
});
