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
use App\Services\AuditLogger;
use App\Services\ContextBudgetedAgentHarness;
use App\Services\ContextBudgetGuard;
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

    expect($resolver->resolve($codexAgent)->capabilities()
        ->resolveContextCapacity($codexAgent, AgentHarnessIdentifier::Codex)['resolved_capacity_tokens'])
        ->toBe(1050000)
        ->and($resolver->resolve($claudeAgent)->capabilities()
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
    $harness = new ContextBudgetedAgentHarness(
        $inner,
        app(ContextBudgetGuard::class),
        app(AuditLogger::class),
    );

    $result = $harness->execute($project, $agent, $prompt);
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
    $firstHarness = new ContextBudgetedAgentHarness(
        p3016Harness(100000),
        app(ContextBudgetGuard::class),
        app(AuditLogger::class),
    );
    expect($firstHarness->execute($project, $agent, $prompt)->exitCode)->toBe(0);
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
    $secondHarness = new ContextBudgetedAgentHarness(
        p3016Harness(200000),
        app(ContextBudgetGuard::class),
        app(AuditLogger::class),
    );

    expect($secondHarness->execute($project, $agent, $prompt)->exitCode)->toBe(0);
    $second->refresh();

    expect($second->context_budget_snapshot['resolved_capacity_tokens'])->toBe(100000)
        ->and($second->context_budget_snapshot['recovery_snapshot_reused'])->toBeTrue()
        ->and($second->context_budget_snapshot['recovery_snapshot_source_run_id'])->toBe($first->id)
        ->and($first->context_budget_snapshot['resolved_capacity_tokens'])->toBe(100000);
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
