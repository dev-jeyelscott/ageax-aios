<?php

use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskOperatorValidation;
use App\Models\User;
use App\ProjectStatus;
use App\Services\TaskContextCapsuleFactory;
use App\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

function operatorValidationTask(Project $project, TaskStatus $status = TaskStatus::ChangesRequired): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-102',
        'position' => 1,
        'title' => 'Execute Browser and Device Matrix',
        'objective' => 'Manually validate camera permission and capture with physical hardware.',
        'acceptance_criteria' => ['Record browser and device validation results.'],
        'scope' => ['resources/js/pages/kiosk.tsx'],
        'constraints' => ['This is manual, hardware-dependent validation.'],
        'relevant_paths' => ['resources/js/pages/kiosk.tsx'],
        'verification_commands' => ['npm run build'],
        'implementation_prompt' => 'Record the observed hardware validation results.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

/** @return array<string, mixed> */
function operatorValidationPayload(): array
{
    return [
        'build_sha' => '7ebff1367e85cd79bfd1fc9ea3398f6a96acdf66',
        'build_completed_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        'results' => collect(TaskOperatorValidation::Targets)
            ->map(fn (string $label, string $target): array => [
                'target' => $target,
                'browser_version' => 'Latest stable',
                'operating_system_version' => 'Current',
                'camera_label' => $label.' camera',
                'permission' => 'pass',
                'enumeration' => 'pass',
                'switching' => 'pass',
                'capture' => 'pass',
                'upload' => 'pass',
                'fullscreen' => 'pass',
                'follow_up_reference' => null,
            ])
            ->values()
            ->all(),
        'notes' => 'Observed by the operator on the production build.',
        'attested' => true,
    ];
}

test('an operator submission records browser and device evidence and sends the task to review', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = operatorValidationTask($project);

    $this->actingAs($user)
        ->post(route('projects.tasks.operator-validations.store', [$project, $task]), operatorValidationPayload())
        ->assertRedirect(route('projects.tasks.show', [$project, $task]));

    $validation = TaskOperatorValidation::query()->sole();

    expect($validation->task_id)->toBe($task->id)
        ->and($validation->user_id)->toBe($user->id)
        ->and($validation->results)->toHaveCount(6)
        ->and($task->refresh()->status)->toBe(TaskStatus::ReadyForReview)
        ->and($task->auditEvents()->where('event_type', 'task.operator_validation_recorded')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'task.operator_validation_ready_for_review')->exists())->toBeTrue();

    $capsule = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Reviewer);

    expect($capsule['operator_validations'])->toHaveCount(1)
        ->and($capsule['operator_validations'][0]['build_sha'])->toBe($validation->build_sha)
        ->and($capsule['operator_validations'][0]['results'])->toHaveCount(6);
});

test('a failed hardware target requires a follow-up reference', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = operatorValidationTask($project);
    $payload = operatorValidationPayload();
    $payload['results'][0]['capture'] = 'fail';

    $this->actingAs($user)
        ->from(route('projects.tasks.show', [$project, $task]))
        ->post(route('projects.tasks.operator-validations.store', [$project, $task]), $payload)
        ->assertRedirect(route('projects.tasks.show', [$project, $task]))
        ->assertSessionHasErrors('results.0.follow_up_reference');

    expect(TaskOperatorValidation::query()->doesntExist())->toBeTrue()
        ->and($task->refresh()->status)->toBe(TaskStatus::ChangesRequired);
});

test('the task detail exposes the operator validation gate only for hardware validation tasks', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = operatorValidationTask($project);

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('operator_validation_available', true));
});
