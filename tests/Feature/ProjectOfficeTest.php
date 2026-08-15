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

test('the project office includes persisted worker context and workers without runs', function () {
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
        'role' => AgentRole::ProjectManager,
        'status' => 'recovering',
    ]);
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Reviewer,
        'status' => 'interrupted',
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
            ->has('project.office_workers', 4)
            ->where('project.office_workers.0.role', 'coder')
            ->where('project.office_workers.0.status', 'working')
            ->where('project.office_workers.0.lease_state', 'active')
            ->where('project.office_workers.0.run.id', $run->id)
            ->where('project.office_workers.0.task.key', 'TASK-001')
            ->where('project.office_workers.1.role', 'knowledge_architect')
            ->where('project.office_workers.1.status', 'mystery')
            ->where('project.office_workers.1.lease_state', 'expired')
            ->where('project.office_workers.1.run', null)
            ->where('project.office_workers.2.role', 'project_manager')
            ->where('project.office_workers.2.status', 'recovering')
            ->where('project.office_workers.2.run', null)
            ->where('project.office_workers.2.task', null)
            ->where('project.office_workers.3.role', 'reviewer')
            ->where('project.office_workers.3.status', 'interrupted')
            ->where('project.office_workers.3.run', null)
            ->where('project.office_workers.3.task', null));
});
