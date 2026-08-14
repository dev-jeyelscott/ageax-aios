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

test('the project office includes live worker context and workers without runs', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Office Example',
        'path' => '/tmp/office-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Build the office',
        'objective' => 'Build the office overview.',
        'acceptance_criteria' => ['The office is available.'],
        'implementation_prompt' => 'Build it.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
    $coder = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'working',
        'last_heartbeat_at' => now(),
        'lease_expires_at' => now()->addMinute(),
    ]);
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Reviewer,
        'status' => 'idle',
    ]);
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::KnowledgeArchitect,
        'status' => 'mystery',
        'lease_expires_at' => now()->subMinute(),
    ]);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_worker_id' => $coder->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'attempt_number' => 2,
        'prompt_hash' => hash('sha256', 'office'),
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->has('officeWorkers', 3)
            ->where('officeWorkers.0.role', 'coder')
            ->where('officeWorkers.0.status', 'working')
            ->where('officeWorkers.0.lease_state', 'active')
            ->where('officeWorkers.0.run.id', $run->id)
            ->where('officeWorkers.0.task.key', 'TASK-001')
            ->where('officeWorkers.1.role', 'knowledge_architect')
            ->where('officeWorkers.1.lease_state', 'expired')
            ->where('officeWorkers.1.run', null)
            ->where('officeWorkers.2.role', 'reviewer')
            ->where('officeWorkers.2.run', null)
            ->where('officeWorkers.2.task', null)
            ->missing('project.office_workers'));
});

test('the project office supports live worker partial reloads', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Office Polling',
        'path' => '/tmp/office-polling-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);

    $response = $this->actingAs($user)
        ->get(route('projects.show', $project));

    $worker->update(['status' => 'working']);

    $response->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('officeWorkers', fn (Assert $reload) => $reload
            ->has('officeWorkers', 1)
            ->where('officeWorkers.0.status', 'working')
            ->missing('project')));
});
