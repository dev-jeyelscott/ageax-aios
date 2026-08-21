<?php

use App\Actions\ConvertTicketToTask;
use App\Actions\RunCoderTask;
use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\ProjectStatus;
use App\Services\TaskContextCapsuleFactory;
use App\TaskStatus;
use App\TicketCategory;
use App\TicketDecision;
use App\TicketPriority;
use App\TicketStatus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

use function Pest\Laravel\mock;

test('an uncaught exception recovering one project ticket conversion does not stop the worker loop from processing a healthy project', function () {
    $failingProject = Project::factory()->create([
        'name' => 'Fault Project A',
        'path' => sys_get_temp_dir().'/ageax-fault-a-'.Str::uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $ticket = Ticket::factory()->for($failingProject)->create(['status' => TicketStatus::Triaging]);
    $decision = [
        'category' => TicketCategory::Enhancement->value,
        'decision' => TicketDecision::Approved->value,
        'confidence' => 0.95,
        'summary' => 'Approved.',
        'documentation_alignment' => [],
        'affected_areas' => ['app/Actions'],
        'complexity' => 'low',
        'requester_reply' => 'Approved.',
        'internal_reason_summary' => 'Bounded change.',
        'questions' => [],
        'blockers' => [],
        'duplicate_ticket_id' => null,
        'suggested_priority' => TicketPriority::Normal->value,
        'implementation_required' => true,
        'proposed_task' => [
            'title' => 'Implement approved work',
            'objective' => 'Implement the approved request.',
            'acceptance_criteria' => ['Behavior implemented.'],
            'scope' => [],
            'constraints' => [],
            'relevant_paths' => [],
            'verification_commands' => [],
            'implementation_prompt' => 'Implement it.',
            'depends_on_task_ids' => [],
            'preferred_phase_id' => null,
        ],
        'escalation_flags' => [],
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => false,
            'automatic_task_conversion_eligible' => true,
            'escalation_reasons' => [],
        ],
    ];
    TicketTriageAttempt::create([
        'ticket_id' => $ticket->id,
        'number' => 1,
        'status' => 'completed',
        'structured_decision' => $decision,
        'claimed_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);

    $healthyProject = Project::factory()->create([
        'name' => 'Fault Project B',
        'path' => sys_get_temp_dir().'/ageax-fault-b-'.Str::uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    foreach ([$failingProject, $healthyProject] as $project) {
        foreach (AgentRole::cases() as $role) {
            AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => 'idle']);
        }
    }
    $healthyTask = Task::create([
        'project_id' => $healthyProject->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Healthy task',
        'objective' => 'Complete successfully.',
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'Codex failed.')]);

    mock(ConvertTicketToTask::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('simulated recovery failure'));

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($healthyTask->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($healthyTask->attempts()->exists())->toBeTrue();
});

test('an unexpected exception during Coder pre-execution setup blocks the task instead of crashing the worker', function () {
    $project = Project::create([
        'name' => 'Fault Coder Project',
        'path' => '/tmp/aios-fault-coder-'.Str::uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
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

    mock(TaskContextCapsuleFactory::class)
        ->shouldReceive('make')
        ->once()
        ->andThrow(new RuntimeException('simulated capsule assembly crash'));

    app(RunCoderTask::class)->handle($task);

    expect($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->attempts()->value('status'))->toBe('failed')
        ->and($task->auditEvents()->where('event_type', 'task.blocked_unexpected_error')->exists())->toBeTrue();
});
