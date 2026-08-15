<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Agent;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\AgentHarnessResolver;
use App\Services\ClaudeCodeHarness;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ReflectionProperty;

function claudeCodeProject(): Project
{
    config()->set(
        'aios.workspace_root',
        dirname(base_path()),
    );

    return Project::create([
        'name' => 'Claude Code '
            .fake()->unique()->uuid(),
        'path' => base_path(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/** @param array<string, mixed> $attributes */
function claudeCodeAgent(
    Project $project,
    AgentRole $role = AgentRole::Coder,
    array $attributes = [],
): Agent {
    return Agent::factory()
        ->for($project)
        ->create([
            'role' => $role,
            'harness' => AgentHarnessIdentifier::ClaudeCode,
            ...$attributes,
        ]);
}

function claudeCodeSuccessStream(
    string $result = 'completed',
): string {
    return implode("\n", [
        json_encode([
            'type' => 'system',
            'subtype' => 'init',
            'session_id' => 'claude-session-123',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'type' => 'result',
            'subtype' => 'success',
            'session_id' => 'claude-session-123',
            'duration_ms' => 1250,
            'duration_api_ms' => 900,
            'is_error' => false,
            'num_turns' => 2,
            'result' => $result,
            'stop_reason' => 'end_turn',
            'total_cost_usd' => 0.0123,
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 30,
            ],
            'permission_denials' => [],
        ], JSON_THROW_ON_ERROR),
    ])."\n";
}

function fakeClaudeCodeExecution(
    string $output,
    string $errorOutput = '',
    int $exitCode = 0,
): void {
    Process::fake([
        '*' => Process::sequence([
            Process::result(
                output: '{"loggedIn":true}',
                exitCode: 0,
            ),
            Process::result(
                output: $output,
                errorOutput: $errorOutput,
                exitCode: $exitCode,
            ),
        ]),
    ]);
}

test('identifies and resolves the claude code harness', function () {
    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);

    $harness = app(
        AgentHarnessResolver::class,
    )->resolve($agent);

    expect($harness)
        ->toBeInstanceOf(ClaudeCodeHarness::class)
        ->and($harness->identifier())
        ->toBe(AgentHarnessIdentifier::ClaudeCode)
        ->and($harness->capabilities()->models)
        ->toBe([
            'claude-fable-5',
            'claude-opus-5',
            'claude-sonnet-5',
            'claude-haiku-4-5-20251001',
        ])
        ->and(
            $harness
                ->capabilities()
                ->reasoningSettings,
        )
        ->toBe([
            'low',
            'medium',
            'high',
            'xhigh',
            'max',
        ])
        ->and(
            $harness
                ->capabilities()
                ->configurationFields,
        )
        ->toBe([
            'model',
            'reasoning_setting',
        ])
        ->and(
            $harness
                ->capabilities()
                ->reasoningSettingsByModel,
        )
        ->toBe([
            'claude-fable-5' => [
                'low',
                'medium',
                'high',
                'xhigh',
                'max',
            ],
            'claude-opus-5' => [
                'low',
                'medium',
                'high',
                'xhigh',
                'max',
            ],
            'claude-sonnet-5' => [
                'low',
                'medium',
                'high',
                'xhigh',
                'max',
            ],
            'claude-haiku-4-5-20251001' => [],
        ])
        ->and(
            $harness
                ->capabilities()
                ->executionOptions,
        )
        ->toBe([
            'ephemeral',
            'streaming',
            'heartbeat',
        ]);
});

test('executes every core workflow role through claude code', function (AgentRole $role) {
    fakeClaudeCodeExecution(
        claudeCodeSuccessStream($role->value),
    );

    $project = claudeCodeProject();
    $agent = claudeCodeAgent(
        $project,
        $role,
    );

    $result = app(
        AgentHarnessResolver::class,
    )
        ->resolve($agent)
        ->execute(
            $project,
            $agent,
            'Perform the role task.',
        );

    expect($result->exitCode)
        ->toBe(0)
        ->and($result->output)
        ->toBe($role->value);
})->with([
    'project manager' => AgentRole::ProjectManager,
    'coder' => AgentRole::Coder,
    'reviewer' => AgentRole::Reviewer,
]);

test('uses the official non interactive contract with isolated environment and stdin prompt input', function () {
    $originalDbPassword = getenv('DB_PASSWORD');
    $originalApiToken = getenv(
        'AIOS_TEST_API_TOKEN',
    );
    $originalAnthropicApiKey = getenv(
        'ANTHROPIC_API_KEY',
    );
    $originalAnthropicAuthToken = getenv(
        'ANTHROPIC_AUTH_TOKEN',
    );
    $originalClaudeOauthToken = getenv(
        'CLAUDE_CODE_OAUTH_TOKEN',
    );

    putenv('DB_PASSWORD=must-not-reach-claude');
    putenv(
        'AIOS_TEST_API_TOKEN=must-not-reach-claude',
    );
    putenv(
        'ANTHROPIC_API_KEY=must-not-reach-claude',
    );
    putenv(
        'ANTHROPIC_AUTH_TOKEN=must-not-reach-claude',
    );
    putenv(
        'CLAUDE_CODE_OAUTH_TOKEN=must-not-reach-claude',
    );

    fakeClaudeCodeExecution(
        claudeCodeSuccessStream(),
    );

    $project = claudeCodeProject();
    $agent = claudeCodeAgent(
        $project,
        attributes: [
            'model' => 'claude-sonnet-5',
            'reasoning_setting' => 'xhigh',
        ],
    );

    $prompt =
        'Implement the task without exposing secrets.';

    try {
        app(ClaudeCodeHarness::class)->execute(
            $project,
            $agent,
            $prompt,
        );

        Process::assertRan(
            function (
                PendingProcess $process,
            ) use ($prompt): bool {
                $command = (
                    new ReflectionProperty(
                        $process,
                        'command',
                    )
                )->getValue($process);

                if (
                    ! is_array($command)
                    || ! in_array(
                        '-p',
                        $command,
                        true,
                    )
                ) {
                    return false;
                }

                $input = (
                    new ReflectionProperty(
                        $process,
                        'input',
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
                    && in_array(
                        config(
                            'aios.claude_code_binary',
                        ),
                        $command,
                        true,
                    )
                    && in_array(
                        '--safe-mode',
                        $command,
                        true,
                    )
                    && in_array(
                        '--no-session-persistence',
                        $command,
                        true,
                    )
                    && in_array(
                        '--no-chrome',
                        $command,
                        true,
                    )
                    && in_array(
                        '--strict-mcp-config',
                        $command,
                        true,
                    )
                    && in_array(
                        'stream-json',
                        $command,
                        true,
                    )
                    && in_array(
                        '--include-partial-messages',
                        $command,
                        true,
                    )
                    && in_array(
                        'dontAsk',
                        $command,
                        true,
                    )
                    && in_array(
                        '--model',
                        $command,
                        true,
                    )
                    && in_array(
                        'claude-sonnet-5',
                        $command,
                        true,
                    )
                    && in_array(
                        '--effort',
                        $command,
                        true,
                    )
                    && in_array(
                        'xhigh',
                        $command,
                        true,
                    )
                    && ! in_array(
                        '--bare',
                        $command,
                        true,
                    )
                    && ! in_array(
                        $prompt,
                        $command,
                        true,
                    )
                    && $input === $prompt
                    && ! collect($command)
                        ->contains(
                            fn (
                                string $argument,
                            ): bool => str_starts_with(
                                $argument,
                                'DB_',
                            ),
                        )
                    && ! collect($command)
                        ->contains(
                            fn (
                                string $argument,
                            ): bool => str_starts_with(
                                $argument,
                                'AIOS_TEST_API_TOKEN=',
                            ),
                        )
                    && ! collect($command)
                        ->contains(
                            fn (
                                string $argument,
                            ): bool => str_starts_with(
                                $argument,
                                'ANTHROPIC_API_KEY=',
                            ),
                        )
                    && ! collect($command)
                        ->contains(
                            fn (
                                string $argument,
                            ): bool => str_starts_with(
                                $argument,
                                'ANTHROPIC_AUTH_TOKEN=',
                            ),
                        )
                    && ! collect($command)
                        ->contains(
                            fn (
                                string $argument,
                            ): bool => str_starts_with(
                                $argument,
                                'CLAUDE_CODE_OAUTH_TOKEN=',
                            ),
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

        $originalAnthropicApiKey === false
            ? putenv('ANTHROPIC_API_KEY')
            : putenv(
                'ANTHROPIC_API_KEY='
                .$originalAnthropicApiKey,
            );

        $originalAnthropicAuthToken === false
            ? putenv('ANTHROPIC_AUTH_TOKEN')
            : putenv(
                'ANTHROPIC_AUTH_TOKEN='
                .$originalAnthropicAuthToken,
            );

        $originalClaudeOauthToken === false
            ? putenv('CLAUDE_CODE_OAUTH_TOKEN')
            : putenv(
                'CLAUDE_CODE_OAUTH_TOKEN='
                .$originalClaudeOauthToken,
            );
    }
});

test('limits non coder agents to inspection oriented tools', function (AgentRole $role) {
    fakeClaudeCodeExecution(
        claudeCodeSuccessStream(),
    );

    $project = claudeCodeProject();
    $agent = claudeCodeAgent(
        $project,
        $role,
    );

    app(ClaudeCodeHarness::class)->execute(
        $project,
        $agent,
        'Inspect the project.',
    );

    Process::assertRan(
        function (PendingProcess $process): bool {
            $command = (
                new ReflectionProperty(
                    $process,
                    'command',
                )
            )->getValue($process);

            if (
                ! is_array($command)
                || ! in_array(
                    '-p',
                    $command,
                    true,
                )
            ) {
                return false;
            }

            $toolsIndex = array_search(
                '--tools',
                $command,
                true,
            );
            $allowedIndex = array_search(
                '--allowedTools',
                $command,
                true,
            );

            return is_int($toolsIndex)
                && is_int($allowedIndex)
                && (
                    $command[$toolsIndex + 1]
                    ?? null
                ) === 'Bash,Read,Glob,Grep'
                && (
                    $command[$allowedIndex + 1]
                    ?? null
                ) === 'Read,Glob,Grep';
        },
    );
})->with([
    'project manager' => AgentRole::ProjectManager,
    'reviewer' => AgentRole::Reviewer,
]);

test('allows coder implementation tools while denying direct git lifecycle mutations', function () {
    fakeClaudeCodeExecution(
        claudeCodeSuccessStream(),
    );

    $project = claudeCodeProject();
    $agent = claudeCodeAgent(
        $project,
        AgentRole::Coder,
    );

    app(ClaudeCodeHarness::class)->execute(
        $project,
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

            if (
                ! is_array($command)
                || ! in_array(
                    '-p',
                    $command,
                    true,
                )
            ) {
                return false;
            }

            $toolsIndex = array_search(
                '--tools',
                $command,
                true,
            );
            $allowedIndex = array_search(
                '--allowedTools',
                $command,
                true,
            );
            $deniedIndex = array_search(
                '--disallowedTools',
                $command,
                true,
            );

            $denied = is_int($deniedIndex)
                ? (string) (
                    $command[$deniedIndex + 1]
                    ?? ''
                )
                : '';

            return is_int($toolsIndex)
                && is_int($allowedIndex)
                && (
                    $command[$toolsIndex + 1]
                    ?? null
                )
                    ===
                    'Bash,Edit,Write,Read,Glob,Grep'
                && (
                    $command[$allowedIndex + 1]
                    ?? null
                )
                    ===
                    'Bash,Edit,Write,Read,Glob,Grep'
                && str_contains(
                    $denied,
                    'Bash(git commit *)',
                )
                && str_contains(
                    $denied,
                    'Bash(git push *)',
                )
                && str_contains(
                    $denied,
                    'Bash(git reset *)',
                )
                && str_contains(
                    $denied,
                    'Bash(git stash *)',
                );
        },
    );
});

test('streams claude output and renews heartbeats', function () {
    config()->set(
        'aios.worker_heartbeat_interval_seconds',
        1,
    );

    Process::fake([
        '*' => Process::sequence([
            Process::result(
                output: '{"loggedIn":true}',
                exitCode: 0,
            ),
            Process::describe()
                ->output(
                    claudeCodeSuccessStream(),
                )
                ->exitCode(0)
                ->iterations(5),
        ]),
    ]);

    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);
    $streamed = [];
    $heartbeats = 0;

    $result = app(
        ClaudeCodeHarness::class,
    )->execute(
        $project,
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

    expect($result->exitCode)
        ->toBe(0)
        ->and($streamed)
        ->not->toBeEmpty()
        ->and($heartbeats)
        ->toBeGreaterThan(0);
});

test('normalizes claude result metadata without retaining raw provider payloads', function () {
    fakeClaudeCodeExecution(
        claudeCodeSuccessStream(
            '{"outcome":"approved"}',
        ),
    );

    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);

    $result = app(
        ClaudeCodeHarness::class,
    )->execute(
        $project,
        $agent,
        'Review the task.',
    );

    expect($result->exitCode)
        ->toBe(0)
        ->and($result->output)
        ->toBe('{"outcome":"approved"}')
        ->and($result->errorOutput)
        ->toBe('')
        ->and($result->externalRunId)
        ->toBe('claude-session-123')
        ->and($result->usage)
        ->toBe([
            'input_tokens' => 100,
            'output_tokens' => 30,
        ])
        ->and($result->providerMetadata)
        ->toBe([
            'provider' => 'claude_code',
            'result_subtype' => 'success',
            'duration_ms' => 1250,
            'duration_api_ms' => 900,
            'num_turns' => 2,
            'stop_reason' => 'end_turn',
            'total_cost_usd' => 0.0123,
        ]);
});

test('fails safely when claude code binary is missing', function () {
    Process::fake([
        '*' => Process::result(
            errorOutput: 'sensitive shell detail',
            exitCode: 127,
        ),
    ]);

    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);

    $result = app(
        ClaudeCodeHarness::class,
    )->execute(
        $project,
        $agent,
        'Implement the task.',
    );

    expect($result->exitCode)
        ->toBe(127)
        ->and($result->errorOutput)
        ->toContain(
            'Claude Code is not installed',
        )
        ->and($result->errorOutput)
        ->not->toContain(
            'sensitive shell detail',
        )
        ->and($result->providerMetadata)
        ->toBe([
            'provider' => 'claude_code',
            'failure_type' => 'missing_binary',
        ]);
});

test('fails safely when claude code authentication is unavailable', function () {
    Process::fake([
        '*' => Process::result(
            output: '{"loggedIn":false}',
            errorOutput: 'sensitive auth detail',
            exitCode: 1,
        ),
    ]);

    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);

    $result = app(
        ClaudeCodeHarness::class,
    )->execute(
        $project,
        $agent,
        'Implement the task.',
    );

    expect($result->exitCode)
        ->toBe(1)
        ->and($result->errorOutput)
        ->toContain('claude auth login')
        ->and($result->errorOutput)
        ->not->toContain(
            'sensitive auth detail',
        )
        ->and(
            $result
                ->providerMetadata[
                    'failure_type'
                ],
        )
        ->toBe(
            'authentication_unavailable',
        );
});

test('normalizes non zero process failures without leaking stderr', function () {
    fakeClaudeCodeExecution(
        'not-json',
        'provider secret detail',
        2,
    );

    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);

    $result = app(
        ClaudeCodeHarness::class,
    )->execute(
        $project,
        $agent,
        'Implement the task.',
    );

    expect($result->exitCode)
        ->toBe(2)
        ->and($result->output)
        ->toBe('')
        ->and($result->errorOutput)
        ->toBe(
            'Claude Code process exited with code 2.',
        )
        ->and($result->errorOutput)
        ->not->toContain(
            'provider secret detail',
        )
        ->and(
            $result
                ->providerMetadata[
                    'failure_type'
                ],
        )
        ->toBe('process_failure');
});

test('normalizes provider result failures and safe api error metadata', function () {
    $stream = implode("\n", [
        json_encode([
            'type' => 'system',
            'subtype' => 'api_retry',
            'error' => 'rate_limit',
            'error_status' => 429,
            'session_id' => 'claude-session-error',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'type' => 'result',
            'subtype' => 'error_during_execution',
            'session_id' => 'claude-session-error',
            'duration_ms' => 500,
            'duration_api_ms' => 400,
            'is_error' => true,
            'num_turns' => 1,
            'stop_reason' => null,
            'total_cost_usd' => 0.001,
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 1,
            ],
            'errors' => [
                'secret provider diagnostic',
            ],
        ], JSON_THROW_ON_ERROR),
    ])."\n";

    fakeClaudeCodeExecution($stream);

    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);

    $result = app(
        ClaudeCodeHarness::class,
    )->execute(
        $project,
        $agent,
        'Implement the task.',
    );

    expect($result->exitCode)
        ->toBe(1)
        ->and($result->externalRunId)
        ->toBe('claude-session-error')
        ->and($result->errorOutput)
        ->toContain(
            'error_during_execution',
        )
        ->and(
            json_encode(
                $result->providerMetadata,
                JSON_THROW_ON_ERROR,
            ),
        )
        ->not->toContain(
            'secret provider diagnostic',
        )
        ->and(
            $result
                ->providerMetadata[
                    'failure_type'
                ],
        )
        ->toBe('provider_failure')
        ->and(
            $result
                ->providerMetadata[
                    'api_error_category'
                ],
        )
        ->toBe('rate_limit')
        ->and(
            $result
                ->providerMetadata[
                    'api_error_status'
                ],
        )
        ->toBe(429);
});

test('returns actionable authentication evidence when runtime oauth fails', function () {
    $stream = implode("\n", [
        json_encode([
            'type' => 'system',
            'subtype' => 'api_retry',
            'error' => 'authentication_failed',
            'error_status' => 401,
            'session_id' => 'claude-session-auth-error',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'type' => 'result',
            'subtype' => 'error_during_execution',
            'session_id' => 'claude-session-auth-error',
            'duration_ms' => 100,
            'duration_api_ms' => 50,
            'is_error' => true,
            'num_turns' => 1,
            'stop_reason' => null,
            'total_cost_usd' => 0.0,
            'usage' => [],
            'errors' => [
                'expired secret credential detail',
            ],
        ], JSON_THROW_ON_ERROR),
    ])."\n";

    fakeClaudeCodeExecution(
        $stream,
        exitCode: 1,
    );

    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);

    $result = app(
        ClaudeCodeHarness::class,
    )->execute(
        $project,
        $agent,
        'Implement the task.',
    );

    expect($result->errorOutput)
        ->toContain('claude auth login')
        ->and(
            $result
                ->providerMetadata[
                    'failure_type'
                ],
        )
        ->toBe(
            'authentication_unavailable',
        )
        ->and(
            $result
                ->providerMetadata[
                    'api_error_category'
                ],
        )
        ->toBe('authentication_failed')
        ->and(
            $result
                ->providerMetadata[
                    'api_error_status'
                ],
        )
        ->toBe(401)
        ->and(
            json_encode(
                $result->providerMetadata,
                JSON_THROW_ON_ERROR,
            ),
        )
        ->not->toContain(
            'expired secret credential detail',
        );
});

test('rejects malformed successful stream output', function () {
    fakeClaudeCodeExecution(
        "{not-json}\n",
    );

    $project = claudeCodeProject();
    $agent = claudeCodeAgent($project);

    $result = app(
        ClaudeCodeHarness::class,
    )->execute(
        $project,
        $agent,
        'Implement the task.',
    );

    expect($result->exitCode)
        ->toBe(1)
        ->and($result->output)
        ->toBe('')
        ->and($result->errorOutput)
        ->toBe(
            'Claude Code returned malformed stream output.',
        )
        ->and(
            $result
                ->providerMetadata[
                    'failure_type'
                ],
        )
        ->toBe('malformed_output');
});

test('converts execution timeout into normalized audit evidence without network access', function () {
    $binary = tempnam(
        sys_get_temp_dir(),
        'aios-claude-timeout-',
    );

    expect($binary)->not->toBeFalse();

    file_put_contents(
        $binary,
        <<<'SH'
#!/bin/sh
if [ "$1" = "auth" ] && [ "$2" = "status" ]; then
    printf '%s\n' '{"loggedIn":true}'
    exit 0
fi
sleep 2
SH,
    );

    chmod($binary, 0700);

    try {
        config()->set(
            'aios.claude_code_binary',
            $binary,
        );
        config()->set(
            'aios.execution_timeout',
            1,
        );

        $project = claudeCodeProject();
        $agent = claudeCodeAgent($project);

        $result = app(
            ClaudeCodeHarness::class,
        )->execute(
            $project,
            $agent,
            'Implement the task.',
        );

        expect($result->exitCode)
            ->toBe(124)
            ->and($result->errorOutput)
            ->toContain('execution timeout')
            ->and(
                $result
                    ->providerMetadata[
                        'failure_type'
                    ],
            )
            ->toBe('timeout');
    } finally {
        @unlink($binary);
    }
});

test('refuses to execute for a persisted project path outside the workspace', function () {
    $workspace =
        sys_get_temp_dir()
        .'/aios-claude-safe-'
        .fake()->uuid();

    mkdir($workspace, 0700, true);

    config()->set(
        'aios.workspace_root',
        $workspace,
    );

    $project = Project::create([
        'name' => 'Unsafe Claude project',
        'path' => base_path(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $agent = Agent::factory()
        ->for($project)
        ->create([
            'harness' => AgentHarnessIdentifier::ClaudeCode,
        ]);

    Process::fake();

    try {
        expect(
            fn () => app(
                ClaudeCodeHarness::class,
            )->execute(
                $project,
                $agent,
                'Implement the task.',
            ),
        )->toThrow(UnsafeProjectPath::class);

        Process::assertNotRan(
            fn (): bool => true,
        );
    } finally {
        @rmdir($workspace);
    }
});
