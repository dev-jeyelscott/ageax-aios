<?php

use App\Models\Project;
use App\Models\ProjectReconciliationRun;
use App\Models\User;
use App\ProjectReconciliationStatus;
use App\ProjectReconciliationTrigger;
use App\ProjectStatus;
use Inertia\Testing\AssertableInertia as Assert;

function reconciliationUiProject(): Project
{
    return Project::create([
        'name' => 'Example',
        'path' => '/tmp/example-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

test('the project page exposes no reconciliation run as never run and not active', function () {
    $project = reconciliationUiProject();

    $this->actingAs(User::factory()->create())
        ->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.reconciliation.latest', null)
            ->where('project.reconciliation.active', false)
        );
});

test('the project page exposes the latest completed reconciliation run with summary counts', function () {
    $project = reconciliationUiProject();

    ProjectReconciliationRun::create([
        'project_id' => $project->id,
        'trigger' => ProjectReconciliationTrigger::Manual,
        'status' => ProjectReconciliationStatus::Completed,
        'evaluated_head_sha' => 'abc123',
        'snapshot_hash' => str_repeat('a', 64),
        'result' => [
            'project_status' => 'Healthy',
            'functionality_summary' => 'Everything works.',
            'new_functionality' => ['Feature A'],
            'changed_functionality' => [],
            'removed_functionality' => [],
            'documentation_drift' => ['README is stale.'],
            'obsidian_findings' => [],
            'risks' => [],
            'recommended_actions' => [],
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.reconciliation.latest.status', 'completed')
            ->where('project.reconciliation.latest.evaluated_head_sha', 'abc123')
            ->where('project.reconciliation.latest.summary_counts.new_functionality', 1)
            ->where('project.reconciliation.latest.summary_counts.documentation_drift', 1)
            ->where('project.reconciliation.active', false)
        );
});

test('the project page reports an active reconciliation run', function () {
    $project = reconciliationUiProject();

    ProjectReconciliationRun::create([
        'project_id' => $project->id,
        'trigger' => ProjectReconciliationTrigger::Scheduled,
        'status' => ProjectReconciliationStatus::Running,
        'started_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.reconciliation.active', true)
        );
});
