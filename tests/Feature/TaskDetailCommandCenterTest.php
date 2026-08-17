<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\ProjectStatus;
use App\ReviewStatus;
use App\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('task detail exposes the durable evidence used by the command center', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Command Center',
        'path' => '/tmp/command-center-'.fake()->uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-054',
        'position' => 54,
        'title' => 'Core CSV Exports',
        'objective' => 'Export required operational reports safely.',
        'acceptance_criteria' => [
            'Permissions and tenant scope are preserved.',
        ],
        'scope' => ['inventory'],
        'constraints' => ['Preserve authorization.'],
        'relevant_paths' => ['app/Http/Controllers/Inventory'],
        'verification_commands' => ['php artisan test --compact'],
        'implementation_prompt' => 'Implement safe CSV exports.',
        'context_capsule' => [],
        'status' => TaskStatus::Done,
    ]);
    $attempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => '9239fb3-base',
        'head_sha' => 'd47f700-head',
        'commit_sha' => 'd47f700-commit',
        'status' => 'completed',
        'validation_results' => [
            'passed' => true,
            'checks' => [
                'git_diff_check' => true,
                'secret_scan' => true,
            ],
        ],
        'changed_files' => [
            'app/Http/Controllers/Inventory/InventoryValuationReportController.php',
            'tests/Feature/Inventory/InventoryValuationReportControllerTest.php',
        ],
        'started_at' => now()->subMinutes(8),
        'finished_at' => now()->subMinutes(3),
    ]);
    $review = Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::Approved,
        'summary' => 'All required evidence passed review.',
        'started_at' => now()->subMinutes(2),
        'completed_at' => now()->subMinute(),
    ]);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'role' => AgentRole::Reviewer,
        'status' => AgentRunStatus::Completed,
        'attempt_number' => 1,
        'prompt_hash' => hash('sha256', 'review'),
        'exit_code' => 0,
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);
    $event = AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'task.approved',
        'payload' => [],
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('task.attempts.0.id', $attempt->id)
            ->where('task.attempts.0.base_sha', '9239fb3-base')
            ->where('task.attempts.0.head_sha', 'd47f700-head')
            ->where('task.attempts.0.commit_sha', 'd47f700-commit')
            ->where('task.attempts.0.validation_results.passed', true)
            ->has('task.attempts.0.changed_files', 2)
            ->where('task.reviews.0.id', $review->id)
            ->where('task.reviews.0.status', ReviewStatus::Approved->value)
            ->where('task.runs.0.id', $run->id)
            ->where('task.runs.0.role', AgentRole::Reviewer->value)
            ->where('task.runs.0.exit_code', 0)
            ->where('task.audit_events.0.id', $event->id));
});
