<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWorkflowController;
use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;
use Illuminate\Routing\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('project show and workflow use distinct controller actions for Wayfinder', function () {
    /** @var Route|null $projectRoute */
    $projectRoute = app('router')
        ->getRoutes()
        ->getByName('projects.show');

    /** @var Route|null $workflowRoute */
    $workflowRoute = app('router')
        ->getRoutes()
        ->getByName('projects.workflow');

    expect($projectRoute)
        ->not->toBeNull();

    expect($workflowRoute)
        ->not->toBeNull();

    expect($projectRoute?->getActionName())
        ->toContain(ProjectController::class.'@show');

    expect($workflowRoute?->getActionName())
        ->toContain(ProjectWorkflowController::class)
        ->not->toBe($projectRoute?->getActionName());
});

test('workflow route renders the existing authoritative project payload without redirecting', function () {
    $user = User::factory()->create();

    $project = Project::create([
        'name' => 'Workflow Wayfinder Regression',
        'path' => '/tmp/workflow-wayfinder-regression-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $workflowUrl = route('projects.workflow', $project);

    $response = $this
        ->actingAs($user)
        ->get($workflowUrl);

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.id', $project->id)
            ->has('project.office_workers')
            ->has('project.token_observability')
            ->has('project.harness_usage')
            ->etc());

    expect($response->headers->has('Location'))
        ->toBeFalse();
});

test('workflow route remains directly refreshable', function () {
    $user = User::factory()->create();

    $project = Project::create([
        'name' => 'Workflow Refresh Regression',
        'path' => '/tmp/workflow-refresh-regression-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $workflowUrl = route('projects.workflow', $project);

    foreach (range(1, 2) as $requestNumber) {
        $response = $this
            ->actingAs($user)
            ->get($workflowUrl);

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.id', $project->id)
                ->etc());

        expect($response->headers->has('Location'))
            ->toBeFalse(
                "Workflow request {$requestNumber} unexpectedly redirected.",
            );
    }
});
