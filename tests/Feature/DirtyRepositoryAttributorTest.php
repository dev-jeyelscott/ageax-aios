<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\DirtyRepositoryAttributor;
use App\Services\ProjectGitState;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function attributorProject(): Project
{
    $path = sys_get_temp_dir().'/aios-attributor-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/baseline.txt', 'baseline');
    Process::path($path)->run(['git', 'add', 'baseline.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);

    return Project::create(['name' => 'Attributor', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'dirty']);
}

function attributorTask(Project $project, string $key, TaskStatus $status, int $position = 1): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => 'Attribution task',
        'objective' => 'Resume once attributed.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('attributes an exact-match dirty file set to the task that abandoned it', function () {
    $project = attributorProject();
    $originTask = attributorTask($project, 'TASK-095', TaskStatus::Blocked);
    $head = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $attempt = TaskAttempt::create(['task_id' => $originTask->id, 'number' => 1, 'base_sha' => $head, 'status' => 'failed', 'changed_files' => ['app/Recipe.php'], 'started_at' => now()]);
    File::ensureDirectoryExists($project->path.'/app');
    File::put($project->path.'/app/Recipe.php', 'stale work');

    $state = app(ProjectGitState::class)->inspect($project->path);
    $matched = app(DirtyRepositoryAttributor::class)->attribute($project, $state);

    expect($matched?->id)->toBe($attempt->id);
});

test('attributes when the abandoned attempt files are a superset of what is currently dirty', function () {
    $project = attributorProject();
    $originTask = attributorTask($project, 'TASK-095', TaskStatus::Blocked);
    $head = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $attempt = TaskAttempt::create(['task_id' => $originTask->id, 'number' => 1, 'base_sha' => $head, 'status' => 'failed', 'changed_files' => ['app/Recipe.php', 'app/AlreadyStagedElsewhere.php'], 'started_at' => now()]);
    File::ensureDirectoryExists($project->path.'/app');
    File::put($project->path.'/app/Recipe.php', 'stale work');

    $state = app(ProjectGitState::class)->inspect($project->path);
    $matched = app(DirtyRepositoryAttributor::class)->attribute($project, $state);

    expect($matched?->id)->toBe($attempt->id);
});

test('refuses to attribute when a dirty file is not explained by any known attempt', function () {
    $project = attributorProject();
    $originTask = attributorTask($project, 'TASK-095', TaskStatus::Blocked);
    $head = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());
    TaskAttempt::create(['task_id' => $originTask->id, 'number' => 1, 'base_sha' => $head, 'status' => 'failed', 'changed_files' => ['app/Recipe.php'], 'started_at' => now()]);
    File::ensureDirectoryExists($project->path.'/app');
    File::put($project->path.'/app/Recipe.php', 'stale work');
    File::put($project->path.'/app/Unexplained.php', 'someone else touched this');

    $state = app(ProjectGitState::class)->inspect($project->path);
    $matched = app(DirtyRepositoryAttributor::class)->attribute($project, $state);

    expect($matched)->toBeNull();
});

test('refuses to attribute when two different tasks equally explain the dirty tree', function () {
    $project = attributorProject();
    $firstTask = attributorTask($project, 'TASK-095', TaskStatus::Blocked, 1);
    $secondTask = attributorTask($project, 'TASK-096', TaskStatus::Failed, 2);
    $head = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());
    TaskAttempt::create(['task_id' => $firstTask->id, 'number' => 1, 'base_sha' => $head, 'status' => 'failed', 'changed_files' => ['app/Recipe.php'], 'started_at' => now()]);
    TaskAttempt::create(['task_id' => $secondTask->id, 'number' => 1, 'base_sha' => $head, 'status' => 'failed', 'changed_files' => ['app/Recipe.php'], 'started_at' => now()]);
    File::ensureDirectoryExists($project->path.'/app');
    File::put($project->path.'/app/Recipe.php', 'stale work');

    $state = app(ProjectGitState::class)->inspect($project->path);
    $matched = app(DirtyRepositoryAttributor::class)->attribute($project, $state);

    expect($matched)->toBeNull();
});

test('an attempt whose base sha no longer matches HEAD is never attributed', function () {
    $project = attributorProject();
    $originTask = attributorTask($project, 'TASK-095', TaskStatus::Blocked);
    TaskAttempt::create(['task_id' => $originTask->id, 'number' => 1, 'base_sha' => 'stale-sha-from-before-a-commit', 'status' => 'failed', 'changed_files' => ['app/Recipe.php'], 'started_at' => now()]);
    File::ensureDirectoryExists($project->path.'/app');
    File::put($project->path.'/app/Recipe.php', 'stale work');

    $state = app(ProjectGitState::class)->inspect($project->path);
    $matched = app(DirtyRepositoryAttributor::class)->attribute($project, $state);

    expect($matched)->toBeNull();
});

test('a committed attempt is never attributed even if the file set otherwise matches', function () {
    $project = attributorProject();
    $originTask = attributorTask($project, 'TASK-095', TaskStatus::Done);
    $head = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());
    TaskAttempt::create(['task_id' => $originTask->id, 'number' => 1, 'base_sha' => $head, 'commit_sha' => 'some-commit-sha', 'status' => 'completed', 'changed_files' => ['app/Recipe.php'], 'started_at' => now()]);
    File::ensureDirectoryExists($project->path.'/app');
    File::put($project->path.'/app/Recipe.php', 'stale work');

    $state = app(ProjectGitState::class)->inspect($project->path);
    $matched = app(DirtyRepositoryAttributor::class)->attribute($project, $state);

    expect($matched)->toBeNull();
});
