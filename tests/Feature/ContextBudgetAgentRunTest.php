<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\ClaudeCodeHarness;
use App\Services\CodexHarness;
use App\Services\ContextBudgetedAgentHarness;
use App\Services\HarnessCapabilities;
use App\Services\NormalizedExecutionResult;

function p3016Harness(int $capacityTokens): AgentHarness
{
    return new class($capacityTokens) implements AgentHarness
    {
        public int $executions = 0;

        public function __construct(private int $capacityTokens) {}

        public function identifier(): AgentHarnessIdentifier
        {
            return AgentHarnessIdentifier::Codex;
        }

        public function capabilities(): HarnessCapabilities
        {
            return new HarnessCapabilities(
                models: ['test-model'],
                reasoningSettings: [],
                defaultContextWindowTokens: $this->capacityTokens,
                defaultMaxOutputTokens: (int) floor($this->capacityTokens * 0.2),
                capacityMetadataSource: 'test-capacity',
                capacityMetadataVersion: 1,
            );
        }

        public function execute(Project $project, Agent $agent, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null): NormalizedExecutionResult
        {
            $this->executions++;

            return new NormalizedExecutionResult(
                exitCode: 0,
                output: '{"ok":true}',
                errorOutput: '',
            );
        }
    };
}

function p3016Project(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-context-budget-run-'.fake()->uuid(),
    ]);
}

test('the resolver exposes context capacity for both registered Codex and Claude Code harnesses', function () {
    $project = p3016Project('Registered harness capacity');
    $codexAgent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => 'gpt-5.6-sol',
    ]);
    $claudeAgent = Agent::factory()->for($project)->create([
        'harness' => AgentHarnessIdentifier::ClaudeCode,
        'model' => 'claude-sonnet-5',
    ]);
    $resolver = app(AgentHarnessResolver::class);

    $codexHarness = $resolver->resolve($codexAgent);
    $claudeHarness = $resolver->resolve($claudeAgent);

    expect($codexHarness)
        ->toBeInstanceOf(CodexHarness::class)
        ->and($codexHarness->capabilities()
            ->resolveContextCapacity($codexAgent, AgentHarnessIdentifier::Codex)['resolved_capacity_tokens'])
        ->toBe(1050000)
        ->and($claudeHarness)
        ->toBeInstanceOf(ClaudeCodeHarness::class)
        ->and($claudeHarness->capabilities()
            ->resolveContextCapacity($claudeAgent, AgentHarnessIdentifier::ClaudeCode)['resolved_capacity_tokens'])
        ->toBe(1000000);
});

test('a hard Context Budget block records immutable evidence and never calls the provider harness', function () {
    $project = p3016Project('Blocked provider dispatch');
    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Coder,
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => null,
        'default_context' => null,
    ]);
    $assembled = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        [
            'task_key' => 'TASK-201',
            'objective' => str_repeat('required ', 26000),
            'acceptance_criteria' => ['Required.'],
        ],
    );
    $prompt = "Coder contract.\n\n".json_encode(
        $assembled->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_id' => $agent->id,
        'role' => AgentRole::Coder,
        'harness' => AgentHarnessIdentifier::Codex->value,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', $prompt),
        'configuration_snapshot' => $assembled->configurationSnapshot(),
        'context_schema_version' => $assembled->contextSchemaVersion,
        'context_cost_estimate' => $assembled->contextCostEstimate,
        'context_cost_schema_version' => $assembled->contextCostSchemaVersion,
        'started_at' => now(),
    ]);
    $inner = p3016Harness(50000);
    $gate = app(ContextBudgetedAgentHarness::class);

    $result = $gate->execute(
        $inner,
        $project,
        $agent,
        $prompt,
        fn (
            string $approvedPrompt,
            ?Closure $onOutput,
            ?Closure $onHeartbeat,
        ): NormalizedExecutionResult => $inner->execute(
            $project,
            $agent,
            $approvedPrompt,
            $onOutput,
            $onHeartbeat,
        ),
    );
    $run->refresh();

    expect($result->exitCode)->toBe(-1)
        ->and($inner->executions)->toBe(0)
        ->and($run->context_budget_snapshot['decision'])->toBe('blocked')
        ->and($run->context_budget_snapshot['block_reason'])->not->toBeNull()
        ->and($project->auditEvents()->where('event_type', 'context_budget.blocked')->exists())->toBeTrue();
});

test('recovery with the same immutable configuration reuses the persisted capacity and policy evidence', function () {
    $project = p3016Project('Recovery budget snapshot');
    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'Recovery phase',
        'objective' => 'Test recovery evidence.',
    ]);
    $task = Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'TASK-RECOVERY',
        'position' => 1,
        'title' => 'Recovery task',
        'objective' => 'Small recovery task.',
        'acceptance_criteria' => ['Preserve evidence.'],
        'implementation_prompt' => 'Implement the recovery task.',
        'context_capsule' => [],
        'status' => 'coding',
    ]);
    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Coder,
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => null,
    ]);
    $assembled = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        [
            'task_key' => $task->key,
            'objective' => 'Small recovery task.',
            'acceptance_criteria' => ['Preserve evidence.'],
        ],
    );
    $prompt = "Coder contract.\n\n".json_encode(
        $assembled->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    $first = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_id' => $agent->id,
        'role' => AgentRole::Coder,
        'harness' => AgentHarnessIdentifier::Codex->value,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', $prompt),
        'configuration_snapshot' => $assembled->configurationSnapshot(),
        'context_schema_version' => $assembled->contextSchemaVersion,
        'context_cost_estimate' => $assembled->contextCostEstimate,
        'context_cost_schema_version' => $assembled->contextCostSchemaVersion,
        'started_at' => now()->subMinute(),
    ]);
    $gate = app(ContextBudgetedAgentHarness::class);
    $firstInner = p3016Harness(100000);

    expect($gate->execute(
        $firstInner,
        $project,
        $agent,
        $prompt,
        fn (
            string $approvedPrompt,
            ?Closure $onOutput,
            ?Closure $onHeartbeat,
        ): NormalizedExecutionResult => $firstInner->execute(
            $project,
            $agent,
            $approvedPrompt,
            $onOutput,
            $onHeartbeat,
        ),
    )->exitCode)->toBe(0);
    $first->refresh()->update(['status' => AgentRunStatus::Interrupted]);
    $first->refresh();

    $second = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_id' => $agent->id,
        'role' => AgentRole::Coder,
        'harness' => AgentHarnessIdentifier::Codex->value,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', $prompt),
        'configuration_snapshot' => $first->configuration_snapshot,
        'context_schema_version' => $assembled->contextSchemaVersion,
        'context_cost_estimate' => $assembled->contextCostEstimate,
        'context_cost_schema_version' => $assembled->contextCostSchemaVersion,
        'started_at' => now(),
    ]);
    $secondInner = p3016Harness(200000);

    expect($gate->execute(
        $secondInner,
        $project,
        $agent,
        $prompt,
        fn (
            string $approvedPrompt,
            ?Closure $onOutput,
            ?Closure $onHeartbeat,
        ): NormalizedExecutionResult => $secondInner->execute(
            $project,
            $agent,
            $approvedPrompt,
            $onOutput,
            $onHeartbeat,
        ),
    )->exitCode)->toBe(0);
    $second->refresh();

    expect($second->context_budget_snapshot['resolved_capacity_tokens'])->toBe(100000)
        ->and($second->context_budget_snapshot['recovery_snapshot_reused'])->toBeTrue()
        ->and($second->context_budget_snapshot['recovery_snapshot_source_run_id'])->toBe($first->id)
        ->and($first->context_budget_snapshot['resolved_capacity_tokens'])->toBe(100000);
});

test('managed assembled prompts cannot bypass the Context Budget gate when their durable AgentRun is missing', function () {
    $project = p3016Project('Missing managed run');
    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Coder,
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => null,
    ]);
    $assembled = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        [
            'task_key' => 'TASK-MISSING-RUN',
            'objective' => 'Preserve the durable budget boundary.',
            'acceptance_criteria' => ['Provider execution remains blocked without run evidence.'],
        ],
    );
    $prompt = "Coder contract.\n\n".json_encode(
        $assembled->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $inner = p3016Harness(100000);
    $gate = app(ContextBudgetedAgentHarness::class);

    $result = $gate->execute(
        $inner,
        $project,
        $agent,
        $prompt,
        fn (
            string $approvedPrompt,
            ?Closure $onOutput,
            ?Closure $onHeartbeat,
        ): NormalizedExecutionResult => $inner->execute(
            $project,
            $agent,
            $approvedPrompt,
            $onOutput,
            $onHeartbeat,
        ),
    );

    expect($result->exitCode)
        ->toBe(-1)
        ->and($result->providerMetadata['failure_type'])
        ->toBe('context_budget_run_missing')
        ->and($inner->executions)
        ->toBe(0);
});

test('legacy no Agent runs remain readable without false Context Budget evidence', function () {
    $project = p3016Project('Legacy Context Budget exception');
    $prompt = 'Legacy workflow prompt.';
    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', $prompt),
        'started_at' => now(),
    ]);

    expect($run->agent_id)->toBeNull()
        ->and($run->configuration_snapshot)->toBeNull()
        ->and($run->context_budget_snapshot)->toBeNull()
        ->and($run->context_budget_schema_version)->toBeNull();
});
