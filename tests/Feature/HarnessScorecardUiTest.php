<?php

use App\AgentHarness;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\ProjectStatus;
use App\ReviewStatus;
use App\Services\CoderHarnessComparableCohortScorecards;
use App\Services\ReviewerHarnessDiagnostics;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function p3022Project(string $name = 'P3-022 scorecard'): Project
{
    return Project::factory()->create([
        'name' => $name.' '.Str::uuid(),
        'path' => sys_get_temp_dir().'/ageax-p3-022-'.Str::uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
}

function p3022CompletedTask(
    Project $project,
    int $position,
    string $harness,
    string $model,
    int $coderTokenUsage,
    TaskWorkType $workType = TaskWorkType::Feature,
    TaskComplexity $complexity = TaskComplexity::Medium,
): Task {
    $task = Task::create([
        'project_id' => $project->id,
        'phase_id' => null,
        'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Scorecard task {$position}",
        'objective' => 'Provide durable scorecard evidence.',
        'work_type' => $workType,
        'complexity' => $complexity,
        'acceptance_criteria' => [
            'The implementation passes deterministic validation and review.',
        ],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => [],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement the bounded task.',
        'context_capsule' => [],
        'status' => TaskStatus::Done,
        'completed_at' => now(),
    ]);

    $attempt = TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'commit_sha' => str_repeat('c', 40),
        'status' => 'completed',
        'validation_results' => [
            'passed' => true,
        ],
        'changed_files' => [],
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);

    $finishedAt = now();

    AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'role' => AgentRole::Coder,
        'harness' => $harness,
        'status' => AgentRunStatus::Completed,
        'attempt_number' => 1,
        'prompt_hash' => hash(
            'sha256',
            "p3-022:coder:{$task->id}:".Str::uuid(),
        ),
        'configuration_snapshot' => [
            'agent' => [
                'id' => null,
                'name' => 'Historical Coder',
                'role' => AgentRole::Coder->value,
                'harness' => $harness,
                'model' => $model,
                'reasoning_setting' => 'high',
                'default_context' => null,
                'configuration_version' => 1,
            ],
        ],
        'context_schema_version' => 2,
        'token_usage' => $coderTokenUsage,
        'exit_code' => 0,
        'started_at' => $finishedAt->copy()->subSeconds(10),
        'finished_at' => $finishedAt,
    ]);

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'review.started',
        'payload' => [
            'attempt_number' => 1,
        ],
        'occurred_at' => now()->subSeconds(8),
    ]);

    AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'role' => AgentRole::Reviewer,
        'harness' => $harness,
        'status' => AgentRunStatus::Completed,
        'attempt_number' => 1,
        'prompt_hash' => hash(
            'sha256',
            "p3-022:reviewer:{$task->id}:".Str::uuid(),
        ),
        'configuration_snapshot' => [
            'agent' => [
                'id' => null,
                'name' => 'Historical Reviewer',
                'role' => AgentRole::Reviewer->value,
                'harness' => $harness,
                'model' => $model,
                'reasoning_setting' => 'high',
                'default_context' => null,
                'configuration_version' => 1,
            ],
        ],
        'context_schema_version' => 2,
        'token_usage' => $harness === AgentHarness::Codex->value
            ? 40
            : 50,
        'exit_code' => 0,
        'started_at' => $finishedAt->copy()->subSeconds(8),
        'finished_at' => $finishedAt,
    ]);

    $review = Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::Approved,
        'summary' => 'Implementation approved.',
        'started_at' => now()->subSeconds(2),
        'completed_at' => now(),
    ]);

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'review.completed',
        'payload' => [
            'attempt_number' => 1,
            'review_id' => $review->id,
            'outcome' => ReviewStatus::Approved->value,
            'finding_count' => 0,
        ],
        'occurred_at' => now(),
    ]);

    return $task;
}

function p3022ComparableTasks(
    Project $project,
    int $count,
    TaskWorkType $workType = TaskWorkType::Feature,
    TaskComplexity $complexity = TaskComplexity::Medium,
): void {
    for ($index = 1; $index <= $count; $index++) {
        $codex = $index % 2 === 1;

        p3022CompletedTask(
            project: $project,
            position: $index,
            harness: $codex
                ? AgentHarness::Codex->value
                : AgentHarness::ClaudeCode->value,
            model: $codex ? 'gpt-5' : 'claude-sonnet',
            coderTokenUsage: $codex ? 100 : 200,
            workType: $workType,
            complexity: $complexity,
        );
    }
}

test('harness scorecards require authentication', function () {
    $this->get(route('harness-scorecards.index'))
        ->assertRedirect(route('login'));
});

test('the system scorecard exposes eligible coder recommendations and reviewer diagnostics from durable evidence', function () {
    $user = User::factory()->create();
    $project = p3022Project('Target project');
    $otherProject = p3022Project('Other project');

    p3022ComparableTasks($project, 20);
    p3022ComparableTasks($otherProject, 4);

    $agent = Agent::factory()
        ->for($project)
        ->create([
            'name' => 'Bound scorecard Coder',
            'role' => AgentRole::Coder,
            'harness' => AgentHarness::Codex,
            'model' => 'gpt-5',
            'reasoning_setting' => 'high',
        ]);

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);

    $agentBefore = $agent->fresh()?->getRawOriginal();
    $workerBefore = $worker->fresh()?->getRawOriginal();

    $response = $this
        ->actingAs($user)
        ->get(route('harness-scorecards.index', [
            'project_id' => $project->id,
            'work_type' => TaskWorkType::Feature->value,
            'complexity' => TaskComplexity::Medium->value,
        ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('harness-scorecards/index')
            ->where('selected_project.id', $project->id)
            ->where(
                'coder_scorecard.selected_cohort.filters.project_repository.project_id',
                $project->id,
            )
            ->where(
                'coder_scorecard.sample.comparable_completed_task_count',
                20,
            )
            ->where(
                'coder_scorecard.confidence.level',
                CoderHarnessComparableCohortScorecards::ConfidenceRecommendationEligible,
            )
            ->where('coder_scorecard.recommendation.eligible', true)
            ->where(
                'coder_scorecard.recommendation.leading_configuration.harness',
                AgentHarness::Codex->value,
            )
            ->where('coder_scorecard.configuration_count_total', 2)
            ->where(
                'coder_scorecard.configuration_scores.0.component_points.quality.total',
                55.0,
            )
            ->missing(
                'coder_scorecard.configuration_scores.0.points',
            )
            ->where(
                'reviewer_diagnostics.methodology_version',
                ReviewerHarnessDiagnostics::MethodologyVersion,
            )
            ->where(
                'reviewer_diagnostics.rates.changes_required.diagnostic_only',
                true,
            )
            ->where(
                'reviewer_diagnostics.recommendation_policy.mode',
                'diagnostic_only',
            )
            ->missing('coder_scorecard.unattributed_outcomes')
            ->missing('coder_scorecard.task_outcomes')
            ->missing('reviewer_diagnostics.review_cycles')
            ->missing('reviewer_diagnostics.unattributed_cycles')
            ->where(
                'links.agent_configuration',
                route('projects.show', [
                    'project' => $project,
                    'tab' => 'agents',
                ]),
            ));

    expect($agent->fresh()?->getRawOriginal())
        ->toBe($agentBefore)
        ->and($worker->fresh()?->getRawOriginal())
        ->toBe($workerBefore);
});

test('insufficient coder evidence never fabricates a recommendation', function () {
    $user = User::factory()->create();
    $project = p3022Project();

    p3022ComparableTasks($project, 4);

    $this
        ->actingAs($user)
        ->get(route('harness-scorecards.index', [
            'project_id' => $project->id,
            'work_type' => TaskWorkType::Feature->value,
            'complexity' => TaskComplexity::Medium->value,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where(
                'coder_scorecard.sample.comparable_completed_task_count',
                4,
            )
            ->where(
                'coder_scorecard.confidence.level',
                CoderHarnessComparableCohortScorecards::ConfidenceInsufficientData,
            )
            ->where('coder_scorecard.recommendation.eligible', false)
            ->where(
                'coder_scorecard.recommendation.leading_configuration',
                null,
            ));
});

test('broadened cohorts are explicit and configuration filters only narrow displayed configuration cards', function () {
    $user = User::factory()->create();
    $project = p3022Project();

    p3022ComparableTasks(
        project: $project,
        count: 6,
        workType: TaskWorkType::Feature,
        complexity: TaskComplexity::Medium,
    );

    $this
        ->actingAs($user)
        ->get(route('harness-scorecards.index', [
            'project_id' => $project->id,
            'work_type' => TaskWorkType::Bug->value,
            'complexity' => TaskComplexity::Medium->value,
            'cohort' => 'broadened',
            'harness' => AgentHarness::Codex->value,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('coder_scorecard.selected_cohort.fallback_level', 1)
            ->where(
                'coder_scorecard.selected_cohort.broadened_dimensions',
                ['work_type'],
            )
            ->where(
                'coder_scorecard.confidence.level',
                CoderHarnessComparableCohortScorecards::ConfidencePreliminary,
            )
            ->where(
                'coder_scorecard.sample.comparable_completed_task_count',
                6,
            )
            ->where('coder_scorecard.configuration_count_total', 2)
            ->where('coder_scorecard.configuration_count_visible', 1)
            ->where(
                'coder_scorecard.configuration_scores.0.configuration.harness',
                AgentHarness::Codex->value,
            )
            ->where('coder_scorecard.matches_filters', true)
            ->where(
                'reviewer_diagnostics.selected_cohort.fallback_level',
                1,
            )
            ->where(
                'reviewer_diagnostics.selected_cohort.broadened_dimensions',
                ['work_type'],
            ));

    $this
        ->actingAs($user)
        ->get(route('harness-scorecards.index', [
            'project_id' => $project->id,
            'work_type' => TaskWorkType::Bug->value,
            'complexity' => TaskComplexity::Medium->value,
            'cohort' => 'exact',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('coder_scorecard.selected_cohort.fallback_level', 1)
            ->where('coder_scorecard.matches_filters', false)
            ->where('reviewer_diagnostics.matches_filters', false));
});

test('scorecard UI remains explicitly advisory token based and navigation only', function () {
    $page = Str::squish(
        file_get_contents(
            resource_path('js/pages/harness-scorecards/index.tsx'),
        ),
    );

    $routes = file_get_contents(base_path('routes/web.php'));

    expect($page)
        ->toContain('Advisory only')
        ->toContain('Read-only')
        ->toContain('insufficient_data')
        ->toContain('Configure manually')
        ->toContain('Token consumption is the')
        ->toContain('Phase 3 cost measure')
        ->toContain('Diagnostic only')
        ->toContain('do not recalculate')
        ->toContain('score.component_points.quality?.total')
        ->toContain('score.component_points.reliability?.total')
        ->toContain('score.component_points.cost_efficiency')
        ->toContain('score.component_points.speed')
        ->not->toContain('score.points.')
        ->not->toContain('price_usd')
        ->not->toContain('provider_pricing')
        ->and($routes)
        ->toContain("Route::get('harness-scorecards'")
        ->not->toContain("Route::post('harness-scorecards'")
        ->not->toContain("Route::patch('harness-scorecards'")
        ->not->toContain("Route::put('harness-scorecards'");
});
