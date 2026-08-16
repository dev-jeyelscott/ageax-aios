<?php

use App\Actions\RunReviewerTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use App\TaskStatus;

use function Pest\Laravel\mock;

test('the reviewer verifies Git evidence in the managed project checkout', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Reviewable task',
        'objective' => 'Review the implementation.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Reviewing,
    ]);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 1, 'status' => 'completed', 'base_sha' => 'base-sha', 'head_sha' => 'head-sha', 'commit_sha' => 'head-sha', 'started_at' => now(), 'finished_at' => now()]);

    mock(CodexCliRunner::class)->shouldReceive('run')->once()->withArgs(function (Project $runProject, string $prompt, mixed ...$unused) use ($project): bool {
        return $runProject->is($project)
            && str_contains($prompt, 'run verification commands only from the managed project checkout')
            && str_contains($prompt, 'Do not create temporary checkouts')
            && str_contains($prompt, 'Never edit files')
            && str_contains($prompt, 'non-empty `findings` array')
            && str_contains($prompt, 'Do not use `actionable_findings`');
    })->andReturn(['exit_code' => 1, 'output' => '', 'error_output' => 'stop after prompt verification']);

    app(RunReviewerTask::class)->run($task, $attempt);
});
