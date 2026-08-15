<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentHarnessResolver;
use App\Services\ClaudeCodeHarness;
use App\Services\CodexHarness;
use App\Services\NormalizedExecutionResult;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

test('project exposes safe harness capability choices for future agent management ui', function () {
    $originalAnthropicApiKey = getenv(
        'ANTHROPIC_API_KEY',
    );
    $secret =
        'must-not-appear-in-capabilities-'
        .fake()->uuid();

    putenv(
        'ANTHROPIC_API_KEY='.$secret,
    );

    $user = User::factory()->create();

    $project = Project::create([
        'name' => 'Harness Capability Project',
        'path' => sys_get_temp_dir()
            .'/harness-capabilities-'
            .fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    try {
        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('projects/show')
                    ->where(
                        'harness_capabilities.codex.configuration_fields',
                        [
                            'model',
                            'reasoning_setting',
                        ],
                    )
                    ->where(
                        'harness_capabilities.codex.models',
                        [
                            'gpt-5.6-sol',
                            'gpt-5.6-terra',
                            'gpt-5.6-luna',
                        ],
                    )
                    ->where(
                        'harness_capabilities.codex.reasoning_settings',
                        [
                            'low',
                            'medium',
                            'high',
                            'xhigh',
                        ],
                    )
                    ->where(
                        'harness_capabilities.claude_code.configuration_fields',
                        [
                            'model',
                            'reasoning_setting',
                        ],
                    )
                    ->where(
                        'harness_capabilities.claude_code.models',
                        [
                            'claude-fable-5',
                            'claude-opus-5',
                            'claude-sonnet-5',
                            'claude-haiku-4-5-20251001',
                        ],
                    )
                    ->where(
                        'harness_capabilities.claude_code.reasoning_settings',
                        [
                            'low',
                            'medium',
                            'high',
                            'xhigh',
                            'max',
                        ],
                    ),
            );

        $serialized = json_encode(
            app(
                AgentHarnessResolver::class,
            )->capabilities(),
            JSON_THROW_ON_ERROR,
        );

        expect($serialized)
            ->not->toContain($secret);
    } finally {
        $originalAnthropicApiKey === false
            ? putenv('ANTHROPIC_API_KEY')
            : putenv(
                'ANTHROPIC_API_KEY='
                .$originalAnthropicApiKey,
            );
    }
});

test('codex rejects an unsupported model before starting a provider process', function () {
    Process::fake();

    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => 'gpt-5.6-unknown',
        'reasoning_setting' => 'high',
    ]);

    expect(
        fn () => app(CodexHarness::class)
            ->execute(
                $agent->project,
                $agent,
                'Implement the task.',
            ),
    )->toThrow(
        LogicException::class,
        'Agent model [gpt-5.6-unknown] is not supported by harness [codex].',
    );

    Process::assertNotRan(
        fn (): bool => true,
    );
});

test('reasoning configuration requires an explicit model instead of depending on a provider default', function () {
    Process::fake();

    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::Codex,
        'model' => null,
        'reasoning_setting' => 'high',
    ]);

    expect(
        fn () => app(
            AgentHarnessResolver::class,
        )->resolve($agent),
    )->toThrow(
        LogicException::class,
        'Agent reasoning setting [high] requires an explicit model for harness [codex].',
    );

    Process::assertNotRan(
        fn (): bool => true,
    );
});

test('claude code rejects unsupported model effort combinations instead of allowing provider downgrade', function () {
    Process::fake();

    $agent = Agent::factory()->create([
        'harness' => AgentHarnessIdentifier::ClaudeCode,
        'model' => 'claude-haiku-4-5-20251001',
        'reasoning_setting' => 'high',
    ]);

    expect(
        fn () => app(
            ClaudeCodeHarness::class,
        )->execute(
            $agent->project,
            $agent,
            'Implement the task.',
        ),
    )->toThrow(
        LogicException::class,
        'Agent reasoning setting [high] is not supported for model [claude-haiku-4-5-20251001] by harness [claude_code].',
    );

    Process::assertNotRan(
        fn (): bool => true,
    );
});

test('unknown provider metadata remains opaque and forward compatible for future run snapshots', function () {
    $providerMetadata = [
        'provider' => 'future_provider',
        'future_scalar' => 'future-value',
        'future_nested' => [
            'schema_version' => 42,
            'provider_specific' => [
                'enabled' => true,
            ],
        ],
    ];

    $result = new NormalizedExecutionResult(
        exitCode: 0,
        output: 'completed',
        errorOutput: '',
        providerMetadata: $providerMetadata,
    );

    expect(
        $result->toArray()[
            'provider_metadata'
        ],
    )->toBe($providerMetadata);
});
