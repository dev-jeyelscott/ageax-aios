<?php

use App\AgentHarness;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\AuditEvent;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\ProjectStatus;
use App\TaskStatus;
use App\TicketPriority;
use App\TicketStatus;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function dashboardProject(string $name = 'Dashboard Project'): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-dashboard-'.Str::uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function dashboardTask(
    Project $project,
    Phase $phase,
    string $key,
    int $position,
    TaskStatus $status,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => $key,
        'position' => $position,
        'title' => 'Dashboard task '.$position,
        'objective' => 'Provide deterministic dashboard test evidence.',
        'acceptance_criteria' => [
            'The dashboard exposes durable task state.',
        ],
        'scope' => ['dashboard'],
        'constraints' => [],
        'relevant_paths' => ['resources/js/pages/dashboard.tsx'],
        'verification_commands' => [
            'php artisan test --compact tests/Feature/DashboardTest.php',
        ],
        'implementation_prompt' => 'Implement the dashboard test task.',
        'context_capsule' => [],
        'status' => $status,
        'completed_at' => $status === TaskStatus::Done
            ? now()
            : null,
    ]);
}

test('dashboard requires authentication', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('dashboard renders deterministic empty state for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('summary.active_projects', 0)
            ->where('summary.open_tasks', 0)
            ->where('summary.enabled_agents', 0)
            ->where('summary.running_executions', 0)
            ->where('summary.open_tickets', 0)
            ->where('summary.active_workers', 0)
            ->has('projects', 0)
            ->where('workflow.queued', 0)
            ->where('workflow.coding', 0)
            ->where('workflow.validating', 0)
            ->where('workflow.ready_for_review', 0)
            ->where('workflow.reviewing', 0)
            ->where('workflow.changes_required', 0)
            ->where('workflow.done', 0)
            ->where('workflow.blocked', 0)
            ->where('agent_console.project', null)
            ->has('recent_activity', 0)
            ->has('open_tickets', 0)
            ->has('generated_at'));
});

test('dashboard exposes real project workflow agent ticket and audit evidence', function () {
    $user = User::factory()->create();
    $project = dashboardProject('AGEAX AIOS 2.0');

    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 3,
        'title' => 'Autonomous Ticketing',
        'objective' => 'Implement Phase 3 ticket workflows.',
    ]);

    $queuedTask = dashboardTask(
        $project,
        $phase,
        'P3-007',
        1,
        TaskStatus::Queued,
    );

    dashboardTask(
        $project,
        $phase,
        'P3-006',
        2,
        TaskStatus::Done,
    );

    $agent = Agent::factory()
        ->for($project)
        ->create([
            'name' => 'Project Manager',
            'role' => AgentRole::ProjectManager,
            'harness' => AgentHarness::Codex,
            'enabled' => true,
        ]);

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'agent_id' => $agent->id,
        'status' => 'working',
        'last_heartbeat_at' => now(),
    ]);

    AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $queuedTask->id,
        'agent_worker_id' => $worker->id,
        'agent_id' => $agent->id,
        'role' => AgentRole::ProjectManager,
        'harness' => AgentHarness::Codex->value,
        'status' => AgentRunStatus::Running,
        'attempt_number' => 1,
        'prompt_hash' => hash('sha256', 'dashboard-running-agent'),
        'started_at' => now()->subMinute(),
    ]);

    $ticket = Ticket::factory()->create([
        'project_id' => $project->id,
        'submitted_by_user_id' => $user->id,
        'status' => TicketStatus::Open,
        'final_priority' => TicketPriority::High,
        'title' => 'Dashboard operations ticket',
    ]);

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $queuedTask->id,
        'event_type' => 'task.coding_started',
        'payload' => [
            'internal_only_evidence' => 'must-not-be-exposed',
        ],
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession([
            'aios.selected_project_id' => $project->id,
        ])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('summary.active_projects', 1)
            ->where('summary.open_tasks', 1)
            ->where('summary.enabled_agents', 1)
            ->where('summary.running_executions', 1)
            ->where('summary.open_tickets', 1)
            ->where('summary.active_workers', 1)
            ->has('projects', 1)
            ->where('projects.0.id', $project->id)
            ->where('projects.0.task_count', 2)
            ->where('projects.0.done_tasks', 1)
            ->where('projects.0.open_tasks', 1)
            ->where('projects.0.progress_percent', 50)
            ->where(
                'projects.0.current_phase.title',
                'Autonomous Ticketing',
            )
            ->where('workflow.queued', 1)
            ->where('workflow.done', 1)
            ->where('agent_console.project.id', $project->id)
            ->where(
                'agent_console.project.name',
                'AGEAX AIOS 2.0',
            )
            ->where(
                'agent_console.agents.0.name',
                'Project Manager',
            )
            ->where(
                'agent_console.agents.0.role',
                AgentRole::ProjectManager->value,
            )
            ->where(
                'agent_console.agents.0.harness',
                AgentHarness::Codex->value,
            )
            ->where(
                'agent_console.agents.0.runtime_status',
                'working',
            )
            ->where(
                'agent_console.agents.0.runtime_source',
                'agent_worker',
            )
            ->where(
                'agent_console.agents.0.last_run.task.key',
                'P3-007',
            )
            ->has('recent_activity', 1)
            ->where(
                'recent_activity.0.event_type',
                'task.coding_started',
            )
            ->missing('recent_activity.0.payload')
            ->has('open_tickets', 1)
            ->where('open_tickets.0.id', $ticket->id)
            ->where(
                'open_tickets.0.title',
                'Dashboard operations ticket',
            )
            ->where(
                'open_tickets.0.priority',
                TicketPriority::High->value,
            ));
});

test('dashboard excludes completed tasks and closed tickets from open summary counts', function () {
    $user = User::factory()->create();
    $project = dashboardProject();

    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'Completed Phase',
        'objective' => 'Verify completed work is not open.',
    ]);

    dashboardTask(
        $project,
        $phase,
        'TASK-001',
        1,
        TaskStatus::Done,
    );

    Ticket::factory()->create([
        'project_id' => $project->id,
        'submitted_by_user_id' => $user->id,
        'status' => TicketStatus::Closed,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.open_tasks', 0)
            ->where('summary.open_tickets', 0)
            ->where('workflow.done', 1)
            ->has('open_tickets', 0));
});
