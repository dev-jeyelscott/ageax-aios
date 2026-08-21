<?php

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

function taskForRecoveryEscalationPayload(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Inspect recovery escalation evidence',
        'objective' => 'Expose bounded recovery escalation evidence on task detail.',
        'acceptance_criteria' => ['Recovery escalation reason is rendered safely.'],
        'implementation_prompt' => 'Preserve durable recovery escalation evidence.',
        'context_capsule' => [],
        'status' => TaskStatus::Blocked,
    ]);
}

test('task detail exposes a durable recovery escalation reason', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Recovery Evidence',
        'path' => '/tmp/recovery-evidence-'.fake()->uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
    $task = taskForRecoveryEscalationPayload($project);

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'recovery.escalated',
        'payload' => [
            'escalation_reason' => 'Repository state requires operator judgment.',
        ],
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where(
                'recovery_escalation_reason',
                'Repository state requires operator judgment.',
            ));
});

test('task detail ignores a malformed recovery escalation payload', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Malformed Recovery Evidence',
        'path' => '/tmp/malformed-recovery-evidence-'.fake()->uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
    $task = taskForRecoveryEscalationPayload($project);

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'recovery.escalated',
        'payload' => 'legacy-malformed-payload',
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('recovery_escalation_reason', null));
});
