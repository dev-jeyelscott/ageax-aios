<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use App\Models\Project;
use App\Services\AgentHarness as AgentHarnessContract;
use App\Services\AgentHarnessResolver;
use App\Services\HarnessCapabilities;
use App\Services\NormalizedExecutionResult;
use Illuminate\Support\Facades\DB;

function p2005Harness(AgentHarnessIdentifier $identifier): AgentHarnessContract
{
    return new class($identifier) implements AgentHarnessContract
    {
        public function __construct(private AgentHarnessIdentifier $identifier) {}

        public function identifier(): AgentHarnessIdentifier
        {
            return $this->identifier;
        }

        public function capabilities(): HarnessCapabilities
        {
            return new HarnessCapabilities(
                models: ['test-model'],
                reasoningSettings: ['high'],
                executionOptions: ['streaming'],
            );
        }

        public function execute(Project $project, Agent $agent, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null, array $executionSettings = []): NormalizedExecutionResult
        {
            if ($onOutput !== null) {
                $onOutput('stdout', 'streamed output');
            }

            if ($onHeartbeat !== null) {
                $onHeartbeat();
            }

            return new NormalizedExecutionResult(
                exitCode: 0,
                output: 'completed',
                errorOutput: '',
                externalRunId: 'provider-run-123',
                usage: ['input_tokens' => 10, 'output_tokens' => 5],
                providerMetadata: [
                    'request_id' => 'request-123',
                    'provider' => $this->identifier->value,
                ],
            );
        }
    };
}

test('resolves the executable harness from the persisted agent harness configuration', function () {
    $codexAgent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
    ]);
    $claudeAgent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::ClaudeCode,
    ]);

    $codexHarness = p2005Harness(AgentHarnessIdentifier::Codex);
    $claudeHarness = p2005Harness(AgentHarnessIdentifier::ClaudeCode);

    $resolver = new AgentHarnessResolver([
        $codexHarness,
        $claudeHarness,
    ]);

    expect($resolver->resolve($codexAgent))->toBe($codexHarness)
        ->and($resolver->resolve($claudeAgent))->toBe($claudeHarness);
});

test('fails deterministically for an unsupported persisted harness identifier', function () {
    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
    ]);

    DB::table('agents')
        ->where('id', $agent->id)
        ->update(['harness' => 'unsupported_harness']);

    $persistedAgent = Agent::query()->findOrFail($agent->id);

    $resolver = new AgentHarnessResolver([
        p2005Harness(AgentHarnessIdentifier::Codex),
    ]);

    expect(fn () => $resolver->resolve($persistedAgent))
        ->toThrow(
            LogicException::class,
            'Unsupported agent harness identifier [unsupported_harness].',
        );
});

test('fails deterministically when a known harness has no executable implementation yet', function () {
    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::ClaudeCode,
    ]);

    $resolver = new AgentHarnessResolver([
        p2005Harness(AgentHarnessIdentifier::Codex),
    ]);

    expect(fn () => $resolver->resolve($agent))
        ->toThrow(
            LogicException::class,
            'Agent harness [claude_code] has no executable implementation.',
        );
});

test('the harness contract preserves callbacks capabilities and provider audit metadata', function () {
    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
    ]);

    $harness = p2005Harness(AgentHarnessIdentifier::Codex);

    $streamed = [];
    $heartbeats = 0;

    $result = $harness->execute(
        $agent->project,
        $agent,
        'Implement the task.',
        function (string $type, string $output) use (&$streamed): void {
            $streamed[] = [$type, $output];
        },
        function () use (&$heartbeats): void {
            $heartbeats++;
        },
    );

    expect($streamed)->toBe([
        ['stdout', 'streamed output'],
    ])
        ->and($heartbeats)->toBe(1)
        ->and($harness->capabilities()->models)->toBe(['test-model'])
        ->and($harness->capabilities()->reasoningSettings)->toBe(['high'])
        ->and($harness->capabilities()->executionOptions)->toBe(['streaming'])
        ->and($result->externalRunId)->toBe('provider-run-123')
        ->and($result->usage)->toBe([
            'input_tokens' => 10,
            'output_tokens' => 5,
        ])
        ->and($result->providerMetadata)->toBe([
            'request_id' => 'request-123',
            'provider' => 'codex',
        ])
        ->and($result->toArray())->toBe([
            'exit_code' => 0,
            'output' => 'completed',
            'error_output' => '',
            'external_run_id' => 'provider-run-123',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
            'provider_metadata' => [
                'request_id' => 'request-123',
                'provider' => 'codex',
            ],
        ]);
});
