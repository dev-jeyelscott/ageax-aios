<?php

use App\AgentHarness;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentRunRecorder;
use App\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('the project office includes persisted worker agent git and harness evidence', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Office Example',
        'path' => '/tmp/office-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $agent = Agent::create([
        'project_id' => $project->id,
        'name' => 'Forge',
        'role' => AgentRole::Coder,
        'harness' => AgentHarness::ClaudeCode,
        'model' => 'sonnet',
        'reasoning_setting' => null,
        'default_context' => null,
        'enabled' => true,
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

    $attempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 2,
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'commit_sha' => str_repeat('c', 40),
        'status' => 'validated',
        'validation_results' => [
            'passed' => true,
            'checks' => [
                'git_diff_check' => true,
                'secret_scan' => true,
                'forbidden_file_check' => true,
                'task_verification' => true,
            ],
        ],
        'changed_files' => [
            'resources/js/pages/projects/show.tsx',
            'resources/js/components/agent-office.tsx',
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $coder = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'agent_id' => $agent->id,
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
        'agent_id' => $agent->id,
        'role' => AgentRole::Coder,
        'harness' => AgentHarness::ClaudeCode->value,
        'status' => AgentRunStatus::Running,
        'attempt_number' => 2,
        'token_usage' => 1200,
        'prompt_hash' => hash('sha256', 'office'),
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->has('project.tasks', 1)
            ->has('project.audit_events')
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
            ->where('project.office_workers.3.task', null)
            ->where('project.workers.0.agent_id', $agent->id)
            ->where('project.agents.0.name', 'Forge')
            ->where('project.agents.0.harness', 'claude_code')
            ->where('project.git_evidence.task.key', 'TASK-001')
            ->where('project.git_evidence.attempt_number', $attempt->number)
            ->where('project.git_evidence.base_sha', str_repeat('a', 40))
            ->where('project.git_evidence.head_sha', str_repeat('b', 40))
            ->where('project.git_evidence.commit_sha', str_repeat('c', 40))
            ->where(
                'project.git_evidence.changed_files.0',
                'resources/js/pages/projects/show.tsx',
            )
            ->where('project.git_evidence.validation_results.passed', true)
            ->where('project.harness_usage.claude_code.run_count', 1)
            ->where('project.harness_usage.claude_code.token_usage', 1200)
            ->where('project.recent_agent_runs.0.harness', 'claude_code'));
});

test('a blocked worker surfaces the harness failure reason for its latest run', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Office Failure Example',
        'path' => '/tmp/office-failure-'.fake()->uuid(),
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
        'status' => TaskStatus::Blocked,
    ]);

    $coder = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
        'last_heartbeat_at' => now(),
    ]);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_worker_id' => $coder->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'attempt_number' => 3,
        'prompt_hash' => hash('sha256', 'office-failure'),
        'started_at' => now(),
    ]);

    app(AgentRunRecorder::class)->complete($run, [
        'exit_code' => 1,
        'output' => json_encode([
            'type' => 'error',
            'message' => "You've hit your usage limit.",
        ]),
        'error_output' => '',
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.office_workers.0.run.status', 'failed')
            ->where(
                'project.office_workers.0.run.failure_reason',
                "You've hit your usage limit.",
            ));
});
