<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('run detail exposes immutable Context Budget observability evidence', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Context Budget Evidence',
        'path' => '/tmp/context-budget-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Failed,
        'prompt_hash' => hash('sha256', 'budgeted-prompt'),
        'context_budget_schema_version' => 1,
        'context_budget_snapshot' => [
            'schema_version' => 1,
            'policy_version' => 1,
            'decision' => 'blocked',
            'capacity_source' => 'openai_model_docs_2026_08_19:model',
            'capacity_source_version' => 1,
            'resolved_capacity_tokens' => 1050000,
            'target_percent' => 70,
            'warning_percent' => 75,
            'hard_ceiling_percent' => 80,
            'reserved_percent' => 20,
            'original_estimated_tokens' => 900000,
            'final_estimated_tokens' => 850000,
            'required_estimated_tokens' => 850000,
            'utilization_before' => 85.7143,
            'utilization_after' => 80.9524,
            'included_sources' => ['workflow_contract', 'task_required_core'],
            'reduced_sources' => ['agent_default_context'],
            'excluded_sources' => [],
            'warning_reason' => 'estimated_context_at_or_above_warning_threshold',
            'block_reason' => 'required_context_reaches_or_exceeds_hard_ceiling',
            'final_context_hash' => hash('sha256', 'final-context'),
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.agent-runs.show', [$project, $run]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/agent-runs/show')
            ->where('agent_run.context_budget_schema_version', 1)
            ->where('agent_run.context_budget_snapshot.policy_version', 1)
            ->where('agent_run.context_budget_snapshot.resolved_capacity_tokens', 1050000)
            ->where('agent_run.context_budget_snapshot.target_percent', 70)
            ->where('agent_run.context_budget_snapshot.warning_percent', 75)
            ->where('agent_run.context_budget_snapshot.hard_ceiling_percent', 80)
            ->where('agent_run.context_budget_snapshot.decision', 'blocked')
            ->where('agent_run.context_budget_snapshot.reduced_sources.0', 'agent_default_context'));
});

test('legacy AgentRun detail remains readable without Context Budget evidence', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Legacy Context Budget Evidence',
        'path' => '/tmp/context-budget-legacy-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'legacy-prompt'),
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.agent-runs.show', [$project, $run]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/agent-runs/show')
            ->where('agent_run.context_budget_snapshot', null)
            ->where('agent_run.context_budget_schema_version', null));
});
