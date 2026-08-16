<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

function officeSyncProject(string $name): Project
{
    return Project::create([
        'name' => $name,
        'path' => '/tmp/'.str($name)->slug().'-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function officeSyncTask(
    Project $project,
    string $key,
    int $position,
    TaskStatus $status,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => "{$key} task",
        'objective' => "Execute {$key}.",
        'acceptance_criteria' => ['The task is represented correctly.'],
        'implementation_prompt' => "Implement {$key}.",
        'context_capsule' => [],
        'status' => $status,
    ]);
}

function officeSyncWorker(
    Project $project,
    AgentRole $role,
    string $status = 'idle',
    bool $activeLease = false,
): AgentWorker {
    return AgentWorker::create([
        'project_id' => $project->id,
        'role' => $role,
        'status' => $status,
        'last_heartbeat_at' => now(),
        'lease_expires_at' => $activeLease ? now()->addMinute() : null,
    ]);
}

function officeSyncRun(
    Project $project,
    AgentWorker $worker,
    ?Task $task,
    AgentRunStatus $status,
    int $minutesAgo = 0,
): AgentRun {
    return AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task?->id,
        'agent_worker_id' => $worker->id,
        'role' => $worker->role,
        'status' => $status,
        'prompt_hash' => hash('sha256', implode(':', [
            $project->id,
            $worker->id,
            $task?->id ?? 'none',
            $minutesAgo,
        ])),
        'started_at' => now()->subMinutes($minutesAgo),
        'finished_at' => $status === AgentRunStatus::Running
            ? null
            : now()->subMinutes($minutesAgo),
    ]);
}

test('office workflow follows the active coder run instead of the first unfinished task', function () {
    $user = User::factory()->create();
    $project = officeSyncProject('Active Coder Sync');
    officeSyncTask($project, 'TASK-020', 1, TaskStatus::ReadyForReview);
    $activeTask = officeSyncTask($project, 'TASK-022', 2, TaskStatus::Coding);
    $coder = officeSyncWorker($project, AgentRole::Coder, 'working', true);
    $run = officeSyncRun($project, $coder, $activeTask, AgentRunStatus::Running);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.office_workers.0.task.key', 'TASK-022')
            ->where('project.office_workers.0.activity_mode', 'current')
            ->where('project.office_workflow.mode', 'current')
            ->where('project.office_workflow.role', 'coder')
            ->where('project.office_workflow.run_id', $run->id)
            ->where('project.office_workflow.task.key', 'TASK-022')
            ->where('project.office_workflow.task.status', 'coding'));
});

test('office workflow follows the reviewer after coder to reviewer handoff', function () {
    $user = User::factory()->create();
    $project = officeSyncProject('Reviewer Handoff Sync');
    $task = officeSyncTask($project, 'TASK-023', 1, TaskStatus::Reviewing);
    $coder = officeSyncWorker($project, AgentRole::Coder);
    $reviewer = officeSyncWorker($project, AgentRole::Reviewer, 'working', true);
    officeSyncRun($project, $coder, $task, AgentRunStatus::Completed, 2);
    $reviewerRun = officeSyncRun($project, $reviewer, $task, AgentRunStatus::Running);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.office_workflow.mode', 'current')
            ->where('project.office_workflow.role', 'reviewer')
            ->where('project.office_workflow.run_id', $reviewerRun->id)
            ->where('project.office_workflow.task.key', 'TASK-023')
            ->where('project.office_workflow.task.status', 'reviewing'));
});

test('office workflow preserves exceptional task status when falling back to recent execution', function (string $status) {
    $user = User::factory()->create();
    $project = officeSyncProject("Recent {$status} Sync");
    officeSyncTask($project, 'TASK-020', 1, TaskStatus::ReadyForReview);
    $recentTask = officeSyncTask($project, 'TASK-022', 2, TaskStatus::from($status));
    $coder = officeSyncWorker($project, AgentRole::Coder);
    officeSyncRun(
        $project,
        $coder,
        $recentTask,
        $status === TaskStatus::Interrupted->value
            ? AgentRunStatus::Interrupted
            : AgentRunStatus::Failed,
    );

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.office_workers.0.activity_mode', 'recent')
            ->where('project.office_workflow.mode', 'recent')
            ->where('project.office_workflow.task.key', 'TASK-022')
            ->where('project.office_workflow.task.status', $status));
})->with([
    TaskStatus::Blocked->value,
    TaskStatus::Interrupted->value,
    TaskStatus::Failed->value,
]);

test('office workflow is isolated to the requested project', function () {
    $user = User::factory()->create();
    $project = officeSyncProject('Requested Project');
    $projectTask = officeSyncTask($project, 'TASK-010', 1, TaskStatus::ReadyForReview);
    $projectCoder = officeSyncWorker($project, AgentRole::Coder);
    officeSyncRun($project, $projectCoder, $projectTask, AgentRunStatus::Completed, 2);

    $otherProject = officeSyncProject('Other Project');
    $otherTask = officeSyncTask($otherProject, 'TASK-999', 1, TaskStatus::Coding);
    $otherCoder = officeSyncWorker($otherProject, AgentRole::Coder, 'working', true);
    officeSyncRun($otherProject, $otherCoder, $otherTask, AgentRunStatus::Running);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.office_workflow.mode', 'recent')
            ->where('project.office_workflow.task.key', 'TASK-010'));
});

test('an active project manager run without a task prevents stale task fallback', function () {
    $user = User::factory()->create();
    $project = officeSyncProject('Project Manager Current Sync');
    $recentTask = officeSyncTask($project, 'TASK-030', 1, TaskStatus::ReadyForReview);
    $coder = officeSyncWorker($project, AgentRole::Coder);
    officeSyncRun($project, $coder, $recentTask, AgentRunStatus::Completed, 2);

    $projectManager = officeSyncWorker(
        $project,
        AgentRole::ProjectManager,
        'working',
        true,
    );
    $projectManagerRun = officeSyncRun(
        $project,
        $projectManager,
        null,
        AgentRunStatus::Running,
    );

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.office_workflow.mode', 'current')
            ->where('project.office_workflow.role', 'project_manager')
            ->where('project.office_workflow.run_id', $projectManagerRun->id)
            ->where('project.office_workflow.task', null));
});
