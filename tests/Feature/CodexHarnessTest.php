<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Agent;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\AgentHarnessResolver;
use App\Services\CodexHarness;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ReflectionProperty;

test('identifies as the codex harness', function () {
    expect(app(CodexHarness::class)->identifier())
        ->toBe(AgentHarnessIdentifier::Codex);
});

test('reports codex execution capabilities', function () {
    $capabilities = app(CodexHarness::class)
        ->capabilities();

    expect($capabilities->models)
        ->toBe([
            'gpt-5.6-sol',
            'gpt-5.6-terra',
            'gpt-5.6-luna',
        ])
        ->and($capabilities->reasoningSettings)
        ->toBe([
            'low',
            'medium',
            'high',
            'xhigh',
        ])
        ->and($capabilities->configurationFields)
        ->toBe([
            'model',
            'reasoning_setting',
        ])
        ->and($capabilities->reasoningSettingsByModel)
        ->toBe([
            'gpt-5.6-sol' => [
                'low',
                'medium',
                'high',
                'xhigh',
            ],
            'gpt-5.6-terra' => [
                'low',
                'medium',
                'high',
                'xhigh',
            ],
            'gpt-5.6-luna' => [
                'low',
                'medium',
                'high',
                'xhigh',
            ],
        ])
        ->and($capabilities->executionOptions)
        ->toBe([
            'ephemeral',
            'streaming',
            'heartbeat',
        ]);
});

test('executes with an explicitly isolated environment', function () {
    $originalDbPassword = getenv('DB_PASSWORD');
    $originalApiToken = getenv(
        'AIOS_TEST_API_TOKEN',
    );

    putenv('DB_PASSWORD=must-not-reach-codex');
    putenv(
        'AIOS_TEST_API_TOKEN=must-not-reach-codex',
    );

    Process::fake([
        '*' => Process::result(
            output: 'completed',
        ),
    ]);

    try {
        $agent = Agent::factory()->create([
            'harness' => AgentHarnessIdentifier::Codex,
        ]);

        app(CodexHarness::class)->execute(
            $agent->project,
            $agent,
            'Implement the task.',
        );

        Process::assertRan(
            function (PendingProcess $process): bool {
                $command = (
                    new ReflectionProperty(
                        $process,
                        'command',
                    )
                )->getValue($process);

                return $command[0] === '/usr/bin/env'
                    && $command[1] === '-i'
                    && in_array(
                        'HOME='.getenv('HOME'),
                        $command,
                        true,
                    )
                    && in_array(
                        'PATH='.getenv('PATH'),
                        $command,
                        true,
                    )
                    && ! collect($command)->contains(
                        fn (string $argument): bool => str_starts_with(
                            $argument,
                            'DB_',
                        ),
                    )
                    && ! collect($command)->contains(
                        fn (string $argument): bool => str_starts_with(
                            $argument,
                            'AIOS_TEST_API_TOKEN=',
                        ),
                    )
                    && in_array(
                        config('aios.codex_binary'),
                        $command,
                        true,
                    );
            },
        );
    } finally {
        $originalDbPassword === false
            ? putenv('DB_PASSWORD')
            : putenv(
                'DB_PASSWORD='
                .$originalDbPassword,
            );

        $originalApiToken === false
            ? putenv('AIOS_TEST_API_TOKEN')
            : putenv(
                'AIOS_TEST_API_TOKEN='
                .$originalApiToken,
            );
    }
});

test('passes validated codex model and reasoning without remapping', function () {
    Process::fake([
        '*' => Process::result(
            output: 'completed',
        ),
    ]);

    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => 'gpt-5.6-sol',
        'reasoning_setting' => 'xhigh',
    ]);

    app(CodexHarness::class)->execute(
        $agent->project,
        $agent,
        'Implement the task.',
    );

    Process::assertRan(
        function (PendingProcess $process): bool {
            $command = (
                new ReflectionProperty(
                    $process,
                    'command',
                )
            )->getValue($process);

            $modelIndex = array_search(
                '--model',
                $command,
                true,
            );
            $configIndex = array_search(
                '--config',
                $command,
                true,
            );

            return is_int($modelIndex)
                && is_int($configIndex)
                && ($command[$modelIndex + 1] ?? null)
                    === 'gpt-5.6-sol'
                && ($command[$configIndex + 1] ?? null)
                    === 'model_reasoning_effort="xhigh"';
        },
    );
});

test('refuses to execute for a persisted project path outside the workspace', function () {
    config()->set(
        'aios.workspace_root',
        sys_get_temp_dir(),
    );

    $project = Project::create([
        'name' => 'Unsafe',
        'path' => base_path(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $agent = Agent::factory()->create([
        'project_id' => $project->id,
        'harness' => AgentHarnessIdentifier::Codex,
    ]);

    Process::fake();

    expect(
        fn () => app(CodexHarness::class)
            ->execute(
                $project,
                $agent,
                'Implement the task.',
            ),
    )->toThrow(UnsafeProjectPath::class);

    Process::assertNotRan(fn (): bool => true);
});

test('streams output and renews heartbeats while executing', function () {
    config()->set(
        'aios.worker_heartbeat_interval_seconds',
        1,
    );

    Process::fake([
        '*' => Process::describe()
            ->output('streamed output')
            ->exitCode(0)
            ->iterations(2),
    ]);

    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
    ]);

    $streamed = [];
    $heartbeats = 0;

    app(CodexHarness::class)->execute(
        $agent->project,
        $agent,
        'Implement the task.',
        function (
            string $type,
            string $output,
        ) use (&$streamed): void {
            $streamed[] = [$type, $output];
        },
        function () use (&$heartbeats): void {
            $heartbeats++;
        },
    );

    expect($streamed)
        ->not->toBeEmpty()
        ->and($heartbeats)
        ->toBeGreaterThan(0);
});

test('normalizes the codex execution result', function () {
    Process::fake([
        '*' => Process::result(
            output: 'stdout-body',
            errorOutput: '',
            exitCode: 0,
        ),
    ]);

    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
    ]);

    $result = app(CodexHarness::class)->execute(
        $agent->project,
        $agent,
        'Implement the task.',
    );

    expect($result->exitCode)
        ->toBe(0)
        ->and(
            rtrim($result->output, "\n"),
        )
        ->toBe('stdout-body')
        ->and($result->errorOutput)
        ->toBe('')
        ->and($result->externalRunId)
        ->toBeNull()
        ->and($result->usage)
        ->toBeNull()
        ->and($result->providerMetadata)
        ->toBe([])
        ->and($result->toArray())
        ->toBe([
            'exit_code' => 0,
            'output' => $result->output,
            'error_output' => '',
            'external_run_id' => null,
            'usage' => null,
            'provider_metadata' => [],
        ]);
});

test('resolves the codex harness from the container-bound resolver', function () {
    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
    ]);

    $harness = app(
        AgentHarnessResolver::class,
    )->resolve($agent);

    expect($harness)
        ->toBeInstanceOf(CodexHarness::class);
});
