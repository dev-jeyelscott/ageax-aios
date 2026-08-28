<?php

use App\Actions\CreateOrchestrationRecommendation;
use App\AgentHarness;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\AuditEvent;
use App\Models\OrchestrationRecommendation;
use App\Models\Project;
use App\Models\User;
use App\OrchestrationRecommendationStatus;
use App\OrchestrationRecommendationType;
use App\ProjectStatus;
use App\Services\AgentContextAssembler;
use App\Services\AgentRunRecorder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create one isolated Project for P5-005 recommendation UI tests.
 */
function p5005Project(string $name = 'P5-005 project'): Project
{
    return Project::factory()->create([
        'name' => $name.' '.Str::uuid(),
        'path' => sys_get_temp_dir().'/ageax-p5005-'.Str::uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
}

/**
 * Resolve the singleton global Orchestrator provisioned by the existing Phase 5 migration.
 */
function p5005Orchestrator(): Agent
{
    return Agent::query()
        ->whereNull('project_id')
        ->where('role', AgentRole::Orchestrator->value)
        ->sole();
}

/**
 * Create a currently bound project Coder configuration for current-versus-suggested UI evidence.
 */
function p5005BoundCoder(Project $project): Agent
{
    $agent = Agent::factory()
        ->for($project)
        ->create([
            'name' => 'P5-005 Bound Coder',
            'role' => AgentRole::Coder,
            'harness' => AgentHarness::Codex,
            'model' => 'current-model',
            'reasoning_setting' => 'medium',
            'default_context' => 'Current bounded Coder context.',
            'enabled' => true,
        ]);

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);

    return $agent;
}

/**
 * Create one completed workerless Orchestrator AgentRun with immutable configuration and retrieval evidence.
 */
function p5005CompletedOrchestratorRun(Project $project): AgentRun
{
    $agent = p5005Orchestrator();

    $context = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Orchestrator,
        [
            'objective' => 'Evaluate bounded durable evidence.',
            'acceptance_criteria' => [
                'Return advisory recommendation evidence only.',
            ],
        ],
    );

    $prompt = json_encode(
        [
            'contract' => 'Evaluate evidence without mutating AIOS state.',
            'context' => $context->toArray(),
        ],
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
    );

    $run = app(AgentRunRecorder::class)->start(
        $project,
        AgentRole::Orchestrator,
        $prompt,
        retrievalManifest: [
            'schema_version' => 1,
            'sources' => [
                [
                    'family' => 'agents',
                    'state' => 'included',
                    'ids' => [$agent->id],
                ],
            ],
        ],
        agent: $agent,
        context: $context,
    );

    $run->update([
        'status' => AgentRunStatus::Completed,
        'exit_code' => 0,
        'finished_at' => now(),
    ]);

    return $run->refresh();
}

/**
 * Persist one realistic Harness/Model recommendation through the existing P5-002 Action.
 */
function p5005HarnessRecommendation(
    Project $project,
): OrchestrationRecommendation {
    $run = p5005CompletedOrchestratorRun($project);

    return app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::HarnessModel,
        1,
        '0.9100',
        [
            'target_role' => AgentRole::Coder->value,
            'harness' => AgentHarness::ClaudeCode->value,
            'model' => 'candidate-model',
            'reason' => 'Durable comparable evidence favors the alternate configuration.',
        ],
        project: $project,
    );
}

/**
 * Capture only immutable recommendation evidence for before-and-after lifecycle assertions.
 *
 * @return array<string, mixed>
 */
function p5005ImmutableRecommendationEvidence(
    OrchestrationRecommendation $recommendation,
): array {
    return [
        'project_id' => $recommendation->project_id,
        'task_id' => $recommendation->task_id,
        'recovery_incident_id' => $recommendation->recovery_incident_id,
        'agent_run_id' => $recommendation->agent_run_id,
        'recommendation_type' => $recommendation->getRawOriginal(
            'recommendation_type',
        ),
        'schema_version' => $recommendation->schema_version,
        'evidence_hash' => $recommendation->evidence_hash,
        'confidence' => $recommendation->confidence,
        'structured_recommendation' => $recommendation->structured_recommendation,
        'created_at' => $recommendation->created_at?->toIso8601String(),
    ];
}

/**
 * Verify both recommendation viewing and lifecycle mutations remain behind authentication.
 */
test('Orchestrator recommendation UI requires authentication', function (): void {
    $project = p5005Project();
    p5005BoundCoder($project);
    $recommendation = p5005HarnessRecommendation($project);

    $this->get(route('orchestrator.recommendations.index'))
        ->assertRedirect(route('login'));

    $this->patch(
        route(
            'orchestrator.recommendations.status.update',
            $recommendation,
        ),
        ['status' => OrchestrationRecommendationStatus::Dismissed->value],
    )->assertRedirect(route('login'));
});

/**
 * Verify the command center exposes advisory recommendation, evidence, AgentRun, and configuration comparison data.
 */
test('command center exposes recommendation evidence without an automatic apply path', function (): void {
    $user = User::factory()->create();
    $project = p5005Project();
    $coder = p5005BoundCoder($project);
    $recommendation = p5005HarnessRecommendation($project);
    $orchestrator = p5005Orchestrator();

    $response = $this
        ->actingAs($user)
        ->get(route('orchestrator.recommendations.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('orchestrator/recommendations/index')
            ->where('summary.total', 1)
            ->where('summary.active', 1)
            ->where('recommendations.data.0.id', $recommendation->id)
            ->where('recommendations.data.0.advisory', true)
            ->where(
                'recommendations.data.0.recommendation_type',
                OrchestrationRecommendationType::HarnessModel->value,
            )
            ->where('recommendations.data.0.confidence', '0.9100')
            ->where(
                'recommendations.data.0.reason',
                'Durable comparable evidence favors the alternate configuration.',
            )
            ->where(
                'recommendations.data.0.current_configuration.id',
                $coder->id,
            )
            ->where(
                'recommendations.data.0.current_configuration.harness',
                AgentHarness::Codex->value,
            )
            ->where(
                'recommendations.data.0.suggested_configuration.harness',
                AgentHarness::ClaudeCode->value,
            )
            ->where(
                'recommendations.data.0.agent_run.configuration_snapshot.agent.id',
                $orchestrator->id,
            )
            ->where(
                'recommendations.data.0.evaluated_evidence.retrieval_manifest.schema_version',
                1,
            )
            ->where(
                'recommendations.data.0.manual_action.label',
                'Open Agent configuration',
            )
            ->where(
                'recommendations.data.0.manual_action.url',
                route('projects.show', [
                    'project' => $project,
                    'tab' => 'agents',
                ]),
            )
            ->etc());

    expect(Route::has('orchestrator.recommendations.apply'))->toBeFalse();
});

/**
 * Verify dismiss is durable, audited, idempotent, and cannot rewrite immutable evidence or Agent configuration.
 */
test('dismiss recommendation is durable audited and idempotent without operational mutation', function (): void {
    $user = User::factory()->create();
    $project = p5005Project();
    $coder = p5005BoundCoder($project);
    $recommendation = p5005HarnessRecommendation($project);
    $orchestrator = p5005Orchestrator();

    $immutableBefore = p5005ImmutableRecommendationEvidence(
        $recommendation,
    );
    $coderBefore = $coder->fresh()?->getRawOriginal();
    $orchestratorBefore = $orchestrator->fresh()?->getRawOriginal();

    $this
        ->actingAs($user)
        ->patch(
            route(
                'orchestrator.recommendations.status.update',
                $recommendation,
            ),
            [
                'status' => OrchestrationRecommendationStatus::Dismissed->value,
            ],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $dismissed = $recommendation->fresh();

    expect($dismissed)
        ->not->toBeNull()
        ->and($dismissed?->getRawOriginal('status'))
        ->toBe(OrchestrationRecommendationStatus::Dismissed->value)
        ->and($dismissed?->status_changed_by_user_id)
        ->toBe($user->id)
        ->and($dismissed?->status_changed_at)
        ->not->toBeNull()
        ->and(
            $dismissed === null
                ? []
                : p5005ImmutableRecommendationEvidence($dismissed),
        )
        ->toEqual($immutableBefore)
        ->and($coder->fresh()?->getRawOriginal())
        ->toEqual($coderBefore)
        ->and($orchestrator->fresh()?->getRawOriginal())
        ->toEqual($orchestratorBefore);

    $audit = AuditEvent::query()
        ->where(
            'event_type',
            'orchestrator.recommendation_dismissed',
        )
        ->sole();

    expect($audit->payload['recommendation_id'])
        ->toBe($recommendation->id)
        ->and($audit->payload['operator_user_id'])
        ->toBe($user->id)
        ->and($audit->payload['evidence_hash'])
        ->toBe($recommendation->evidence_hash);

    $this
        ->actingAs($user)
        ->patch(
            route(
                'orchestrator.recommendations.status.update',
                $recommendation,
            ),
            [
                'status' => OrchestrationRecommendationStatus::Dismissed->value,
            ],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(
        AuditEvent::query()
            ->where(
                'event_type',
                'orchestrator.recommendation_dismissed',
            )
            ->count(),
    )->toBe(1);
});

/**
 * Verify superseded is a one-way terminal lifecycle state and cannot be reactivated or replaced by another decision.
 */
test('supersede recommendation is durable audited and terminal', function (): void {
    $user = User::factory()->create();
    $project = p5005Project();
    p5005BoundCoder($project);
    $recommendation = p5005HarnessRecommendation($project);

    $this
        ->actingAs($user)
        ->patch(
            route(
                'orchestrator.recommendations.status.update',
                $recommendation,
            ),
            [
                'status' => OrchestrationRecommendationStatus::Superseded->value,
            ],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($recommendation->fresh()?->getRawOriginal('status'))
        ->toBe(OrchestrationRecommendationStatus::Superseded->value)
        ->and(
            AuditEvent::query()
                ->where(
                    'event_type',
                    'orchestrator.recommendation_superseded',
                )
                ->count(),
        )
        ->toBe(1);

    $this
        ->actingAs($user)
        ->patch(
            route(
                'orchestrator.recommendations.status.update',
                $recommendation,
            ),
            [
                'status' => OrchestrationRecommendationStatus::Dismissed->value,
            ],
        )
        ->assertRedirect()
        ->assertSessionHasErrors('status');

    expect($recommendation->fresh()?->getRawOriginal('status'))
        ->toBe(OrchestrationRecommendationStatus::Superseded->value);

    $this
        ->actingAs($user)
        ->patch(
            route(
                'orchestrator.recommendations.status.update',
                $recommendation,
            ),
            [
                'status' => OrchestrationRecommendationStatus::Active->value,
            ],
        )
        ->assertSessionHasErrors('status');

    expect($recommendation->fresh()?->getRawOriginal('status'))
        ->toBe(OrchestrationRecommendationStatus::Superseded->value);
});
