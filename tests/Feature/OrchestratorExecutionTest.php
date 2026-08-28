<?php

use App\Actions\RunOrchestratorRecommendation;
use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\OrchestrationRecommendation;
use App\Models\Project;
use App\Models\Task;
use App\OrchestrationRecommendationType;
use App\ProjectStatus;
use App\Services\AgentHarness as AgentHarnessContract;
use App\Services\AgentHarnessResolver;
use App\Services\HarnessCapabilities;
use App\Services\NormalizedExecutionResult;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Create one persisted P5-004 project with a sentinel file that advisory execution must never modify.
 */
function p5004Project(string $name): Project
{
    $path = sys_get_temp_dir().'/ageax-p5004-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    File::put($path.'/P5004-PROJECT-SENTINEL.txt', 'unchanged');

    return Project::create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Resolve and normalize the existing singleton Global Orchestrator fixture for one execution test.
 */
function p5004Orchestrator(): Agent
{
    $agent = Agent::query()
        ->whereNull('project_id')
        ->where('role', AgentRole::Orchestrator->value)
        ->sole();

    $agent->update([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => null,
        'reasoning_setting' => null,
        'enabled' => true,
    ]);

    return $agent->refresh();
}

/**
 * Create one bounded Task scope without adding unrelated workflow state.
 */
function p5004Task(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'P5004-'.fake()->unique()->numberBetween(1000, 9999),
        'position' => 1,
        'title' => 'Structured Orchestrator execution',
        'objective' => 'Validate advisory recommendation execution without durable workflow mutation.',
        'acceptance_criteria' => [
            'Only validated recommendation evidence may persist.',
        ],
        'implementation_prompt' => 'Keep the Global Orchestrator advisory only.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/**
 * Build a fake existing harness implementation with deterministic capability and execution behavior.
 *
 * @param  list<string>  $models
 * @param  list<string>  $reasoningSettings
 */
function p5004Harness(
    Closure $callback,
    AgentHarnessIdentifier $identifier = AgentHarnessIdentifier::Codex,
    array $models = ['supported-model'],
    array $reasoningSettings = ['high'],
): AgentHarnessContract {
    return new class($callback, $identifier, $models, $reasoningSettings) implements AgentHarnessContract
    {
        /**
         * Store deterministic fake harness behavior and capability evidence for the current test.
         *
         * @param  list<string>  $models
         * @param  list<string>  $reasoningSettings
         */
        public function __construct(
            private Closure $callback,
            private AgentHarnessIdentifier $identifier,
            private array $models,
            private array $reasoningSettings,
        ) {}

        /**
         * Return the persisted harness identifier represented by this fake implementation.
         */
        public function identifier(): AgentHarnessIdentifier
        {
            return $this->identifier;
        }

        /**
         * Return the deterministic model and reasoning allowlist enforced by AgentHarnessResolver.
         */
        public function capabilities(): HarnessCapabilities
        {
            $reasoningSettingsByModel = [];

            foreach ($this->models as $model) {
                $reasoningSettingsByModel[$model] = $this->reasoningSettings;
            }

            return new HarnessCapabilities(
                models: $this->models,
                reasoningSettings: $this->reasoningSettings,
                executionOptions: ['ephemeral'],
                configurationFields: [
                    'model',
                    'reasoning_setting',
                ],
                reasoningSettingsByModel: $reasoningSettingsByModel,
            );
        }

        /**
         * Execute the supplied deterministic callback without introducing provider-specific test behavior.
         */
        public function execute(
            Project $project,
            Agent $agent,
            string $prompt,
            ?Closure $onOutput = null,
            ?Closure $onHeartbeat = null,
        ): NormalizedExecutionResult {
            $result = ($this->callback)(
                $project,
                $agent,
                $prompt,
            );

            if (! $result instanceof NormalizedExecutionResult) {
                throw new LogicException(
                    'The P5-004 fake harness callback must return a NormalizedExecutionResult.',
                );
            }

            return $result;
        }
    };
}

/**
 * Replace only the executable harness resolver for the current test while preserving the production contract.
 */
function p5004BindHarness(AgentHarnessContract $harness): void
{
    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([$harness]),
    );
}

/**
 * Build one valid structured execution envelope tied to the exact requested project/task scope.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function p5004Envelope(
    Project $project,
    Agent $orchestrator,
    ?Task $task = null,
    array $overrides = [],
): array {
    return array_replace(
        [
            'schema_version' => 1,
            'proposed_action_category' => OrchestrationRecommendationType::WorkflowImprovement->value,
            'confidence' => 0.9,
            'scope' => [
                'project_id' => $project->id,
                'task_id' => $task?->id,
                'recovery_incident_id' => null,
            ],
            'evidence_references' => [
                [
                    'family' => 'current_agent_configuration',
                    'ids' => [$orchestrator->id],
                ],
            ],
            'recommendation' => [
                'summary' => 'Strengthen deterministic advisory validation.',
                'proposed_change' => 'Keep recommendation execution behind the existing AIOS validation boundary.',
                'reason' => 'The durable evidence supports an advisory-only improvement.',
            ],
        ],
        $overrides,
    );
}

/**
 * Encode one fake provider structured result without changing its semantic values.
 *
 * @param  array<string, mixed>  $payload
 */
function p5004Json(array $payload): string
{
    return json_encode(
        $payload,
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
    );
}

test('valid structured analysis persists advisory evidence from a disposable workerless execution only', function (): void {
    $project = p5004Project('P5-004 valid execution');
    $task = p5004Task($project);
    $orchestrator = p5004Orchestrator();
    $agentConfiguration = [
        'harness' => $orchestrator->getRawOriginal('harness'),
        'model' => $orchestrator->getRawOriginal('model'),
        'reasoning_setting' => $orchestrator->getRawOriginal('reasoning_setting'),
        'configuration_version' => $orchestrator->configuration_version,
    ];
    $taskStatus = $task->getRawOriginal('status');
    $executionPath = null;
    $output = p5004Json(
        p5004Envelope(
            $project,
            $orchestrator,
            $task,
        ),
    );

    p5004BindHarness(
        p5004Harness(
            function (
                Project $executionProject,
                Agent $agent,
                string $prompt,
            ) use (&$executionPath, $output, $project): NormalizedExecutionResult {
                $executionPath = $executionProject->path;

                expect($executionProject->path)
                    ->not->toBe($project->path)
                    ->and($prompt)
                    ->toContain('AIOS assembled context:')
                    ->and($agent->role)
                    ->toBe(AgentRole::Orchestrator);

                return new NormalizedExecutionResult(
                    exitCode: 0,
                    output: $output,
                    errorOutput: '',
                    externalRunId: 'p5004-valid-run',
                );
            },
        ),
    );

    $recommendation = app(
        RunOrchestratorRecommendation::class,
    )->handle(
        $project,
        $task,
    );

    expect($recommendation)
        ->toBeInstanceOf(OrchestrationRecommendation::class)
        ->and($recommendation->recommendation_type)
        ->toBe(OrchestrationRecommendationType::WorkflowImprovement)
        ->and($recommendation->agentRun->getRawOriginal('status'))
        ->toBe(AgentRunStatus::Completed->value)
        ->and($recommendation->agentRun->agent_worker_id)
        ->toBeNull()
        ->and($executionPath)
        ->toBeString()
        ->not->toBe($project->path)
        ->and(is_string($executionPath) && is_dir($executionPath))
        ->toBeFalse()
        ->and(File::get($project->path.'/P5004-PROJECT-SENTINEL.txt'))
        ->toBe('unchanged')
        ->and($task->refresh()->getRawOriginal('status'))
        ->toBe($taskStatus);

    $orchestrator = $orchestrator->refresh();

    expect([
        'harness' => $orchestrator->getRawOriginal('harness'),
        'model' => $orchestrator->getRawOriginal('model'),
        'reasoning_setting' => $orchestrator->getRawOriginal('reasoning_setting'),
        'configuration_version' => $orchestrator->configuration_version,
    ])->toBe($agentConfiguration);
});

test('operational harness failure is distinct from successful execution with malformed recommendation output', function (): void {
    $project = p5004Project('P5-004 failure semantics');
    p5004Orchestrator();

    p5004BindHarness(
        p5004Harness(
            fn (): NormalizedExecutionResult => new NormalizedExecutionResult(
                exitCode: 17,
                output: '',
                errorOutput: 'provider unavailable',
            ),
        ),
    );

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull();

    $operationalRun = AgentRun::query()
        ->where('role', AgentRole::Orchestrator->value)
        ->latest('id')
        ->firstOrFail();

    expect($operationalRun->getRawOriginal('status'))
        ->toBe(AgentRunStatus::Failed->value)
        ->and(
            AuditEvent::query()
                ->where('event_type', 'orchestrator.execution_failed')
                ->exists(),
        )
        ->toBeTrue();

    p5004BindHarness(
        p5004Harness(
            fn (): NormalizedExecutionResult => new NormalizedExecutionResult(
                exitCode: 0,
                output: 'not-json',
                errorOutput: '',
            ),
        ),
    );

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull();

    $rejectedRun = AgentRun::query()
        ->where('role', AgentRole::Orchestrator->value)
        ->latest('id')
        ->firstOrFail();

    expect($rejectedRun->getRawOriginal('status'))
        ->toBe(AgentRunStatus::Completed->value)
        ->and(
            AuditEvent::query()
                ->where('event_type', 'orchestrator.recommendation_rejected')
                ->exists(),
        )
        ->toBeTrue()
        ->and(OrchestrationRecommendation::query()->count())
        ->toBe(0);
});

test('unsupported source harness model and reasoning configuration fail before provider execution', function (): void {
    $project = p5004Project('P5-004 source configuration validation');
    $orchestrator = p5004Orchestrator();
    $executions = 0;

    p5004BindHarness(
        p5004Harness(
            function () use (&$executions): NormalizedExecutionResult {
                $executions++;

                return new NormalizedExecutionResult(
                    exitCode: 0,
                    output: '{}',
                    errorOutput: '',
                );
            },
        ),
    );

    DB::table('agents')
        ->where('id', $orchestrator->id)
        ->update(['harness' => 'unsupported_harness']);

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull();

    DB::table('agents')
        ->where('id', $orchestrator->id)
        ->update([
            'harness' => AgentHarnessIdentifier::Codex->value,
            'model' => 'unsupported-model',
            'reasoning_setting' => null,
        ]);

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull();

    DB::table('agents')
        ->where('id', $orchestrator->id)
        ->update([
            'model' => 'supported-model',
            'reasoning_setting' => 'xhigh',
        ]);

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull()
        ->and($executions)
        ->toBe(0)
        ->and(AgentRun::query()->where('role', AgentRole::Orchestrator->value)->count())
        ->toBe(0)
        ->and(OrchestrationRecommendation::query()->count())
        ->toBe(0);
});

test('unsupported proposed harness model and reasoning combinations are rejected without mutating the target Agent', function (): void {
    $project = p5004Project('P5-004 proposed configuration validation');
    $orchestrator = p5004Orchestrator();
    $initialConfiguration = [
        'harness' => $orchestrator->getRawOriginal('harness'),
        'model' => $orchestrator->getRawOriginal('model'),
        'reasoning_setting' => $orchestrator->getRawOriginal('reasoning_setting'),
    ];

    $unsupportedAgent = p5004Envelope(
        $project,
        $orchestrator,
        overrides: [
            'proposed_action_category' => OrchestrationRecommendationType::HarnessModel->value,
            'recommendation' => [
                'target_role' => AgentRole::ProjectManager->value,
                'harness' => AgentHarnessIdentifier::Codex->value,
                'model' => null,
                'reason' => 'Exercise deterministic target Agent validation.',
            ],
        ],
    );

    p5004BindHarness(
        p5004Harness(
            fn (): NormalizedExecutionResult => new NormalizedExecutionResult(
                exitCode: 0,
                output: p5004Json($unsupportedAgent),
                errorOutput: '',
            ),
        ),
    );

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull();

    $unsupportedModel = p5004Envelope(
        $project,
        $orchestrator,
        overrides: [
            'proposed_action_category' => OrchestrationRecommendationType::HarnessModel->value,
            'recommendation' => [
                'target_role' => AgentRole::Orchestrator->value,
                'harness' => AgentHarnessIdentifier::Codex->value,
                'model' => 'unsupported-model',
                'reason' => 'Exercise deterministic model validation.',
            ],
        ],
    );

    p5004BindHarness(
        p5004Harness(
            fn (): NormalizedExecutionResult => new NormalizedExecutionResult(
                exitCode: 0,
                output: p5004Json($unsupportedModel),
                errorOutput: '',
            ),
        ),
    );

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull();

    $unsupportedHarness = p5004Envelope(
        $project,
        $orchestrator,
        overrides: [
            'proposed_action_category' => OrchestrationRecommendationType::HarnessModel->value,
            'recommendation' => [
                'target_role' => AgentRole::Orchestrator->value,
                'harness' => AgentHarnessIdentifier::ClaudeCode->value,
                'model' => null,
                'reason' => 'Exercise deterministic harness implementation validation.',
            ],
        ],
    );

    p5004BindHarness(
        p5004Harness(
            fn (): NormalizedExecutionResult => new NormalizedExecutionResult(
                exitCode: 0,
                output: p5004Json($unsupportedHarness),
                errorOutput: '',
            ),
        ),
    );

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull();

    $orchestrator->update([
        'model' => 'supported-model',
        'reasoning_setting' => 'high',
    ]);

    $unsupportedReasoning = p5004Envelope(
        $project,
        $orchestrator->refresh(),
        overrides: [
            'proposed_action_category' => OrchestrationRecommendationType::ReasoningLevel->value,
            'recommendation' => [
                'target_role' => AgentRole::Orchestrator->value,
                'reasoning_setting' => 'xhigh',
                'reason' => 'Exercise deterministic reasoning validation.',
            ],
        ],
    );

    p5004BindHarness(
        p5004Harness(
            fn (): NormalizedExecutionResult => new NormalizedExecutionResult(
                exitCode: 0,
                output: p5004Json($unsupportedReasoning),
                errorOutput: '',
            ),
        ),
    );

    expect(app(RunOrchestratorRecommendation::class)->handle($project))
        ->toBeNull()
        ->and(OrchestrationRecommendation::query()->count())
        ->toBe(0)
        ->and(
            AgentRun::query()
                ->where('role', AgentRole::Orchestrator->value)
                ->where('status', AgentRunStatus::Completed->value)
                ->count(),
        )
        ->toBe(4);

    $orchestrator = $orchestrator->refresh();

    expect([
        'harness' => $orchestrator->getRawOriginal('harness'),
        'model' => $orchestrator->getRawOriginal('model'),
        'reasoning_setting' => $orchestrator->getRawOriginal('reasoning_setting'),
    ])->toBe([
        'harness' => $initialConfiguration['harness'],
        'model' => 'supported-model',
        'reasoning_setting' => 'high',
    ]);
});

test('invalid action category confidence scope and evidence references are deterministic recommendation rejections', function (): void {
    $project = p5004Project('P5-004 envelope validation');
    $orchestrator = p5004Orchestrator();
    $payloads = [
        p5004Envelope(
            $project,
            $orchestrator,
            overrides: [
                'proposed_action_category' => 'unsupported_action',
            ],
        ),
        p5004Envelope(
            $project,
            $orchestrator,
            overrides: [
                'confidence' => 1.5,
            ],
        ),
        p5004Envelope(
            $project,
            $orchestrator,
            overrides: [
                'scope' => [
                    'project_id' => $project->id + 1000,
                    'task_id' => null,
                    'recovery_incident_id' => null,
                ],
            ],
        ),
        p5004Envelope(
            $project,
            $orchestrator,
            overrides: [
                'evidence_references' => [
                    [
                        'family' => 'repository_contents',
                        'ids' => [],
                    ],
                ],
            ],
        ),
    ];

    foreach ($payloads as $payload) {
        p5004BindHarness(
            p5004Harness(
                fn (): NormalizedExecutionResult => new NormalizedExecutionResult(
                    exitCode: 0,
                    output: p5004Json($payload),
                    errorOutput: '',
                ),
            ),
        );

        expect(app(RunOrchestratorRecommendation::class)->handle($project))
            ->toBeNull();
    }

    expect(OrchestrationRecommendation::query()->count())
        ->toBe(0)
        ->and(
            AgentRun::query()
                ->where('role', AgentRole::Orchestrator->value)
                ->where('status', AgentRunStatus::Completed->value)
                ->count(),
        )
        ->toBe(count($payloads));
});

test('every Orchestrator invocation creates a fresh execution context from current durable evidence', function (): void {
    $project = p5004Project('P5-004 fresh context');
    $task = p5004Task($project);
    $orchestrator = p5004Orchestrator();
    $prompts = [];
    $output = p5004Json(
        p5004Envelope(
            $project,
            $orchestrator,
            $task,
            [
                'proposed_action_category' => OrchestrationRecommendationType::RetryStrategy->value,
                'recommendation' => [
                    'strategy' => 'fresh_context_retry',
                    'max_attempts' => 2,
                    'backoff_seconds' => 30,
                    'reason' => 'Retry only from current durable evidence.',
                ],
            ],
        ),
    );

    p5004BindHarness(
        p5004Harness(
            function (
                Project $project,
                Agent $agent,
                string $prompt,
            ) use (&$prompts, $output): NormalizedExecutionResult {
                $prompts[] = $prompt;

                return new NormalizedExecutionResult(
                    exitCode: 0,
                    output: $output,
                    errorOutput: '',
                );
            },
        ),
    );

    expect(app(RunOrchestratorRecommendation::class)->handle($project, $task))
        ->toBeInstanceOf(OrchestrationRecommendation::class);

    $task->update([
        'status' => TaskStatus::Blocked,
    ]);

    expect(app(RunOrchestratorRecommendation::class)->handle($project, $task->refresh()))
        ->toBeInstanceOf(OrchestrationRecommendation::class);

    $runs = AgentRun::query()
        ->where('role', AgentRole::Orchestrator->value)
        ->orderBy('id')
        ->get();
    $firstSnapshot = $runs[0]->configuration_snapshot;
    $secondSnapshot = $runs[1]->configuration_snapshot;

    expect($runs)
        ->toHaveCount(2)
        ->and($prompts)
        ->toHaveCount(2)
        ->and($prompts[0])
        ->not->toBe($prompts[1])
        ->and(is_array($firstSnapshot))
        ->toBeTrue()
        ->and(is_array($secondSnapshot))
        ->toBeTrue()
        ->and($firstSnapshot['context_hash'] ?? null)
        ->not->toBe($secondSnapshot['context_hash'] ?? null)
        ->and(OrchestrationRecommendation::query()->count())
        ->toBe(2);
});
