<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use App\Models\Project;
use Closure;

final readonly class CodexHarness implements AgentHarness
{
    private const array Models = [
        'gpt-5.6-sol',
        'gpt-5.6-terra',
        'gpt-5.6-luna',
    ];

    private const array ReasoningSettings = [
        'low',
        'medium',
        'high',
        'xhigh',
    ];

    public function __construct(private CodexCliRunner $runner) {}

    public function identifier(): AgentHarnessIdentifier
    {
        return AgentHarnessIdentifier::Codex;
    }

    public function capabilities(): HarnessCapabilities
    {
        return new HarnessCapabilities(
            models: self::Models,
            reasoningSettings: self::ReasoningSettings,
            executionOptions: [
                'ephemeral',
                'streaming',
                'heartbeat',
            ],
            configurationFields: [
                'model',
                'reasoning_setting',
            ],
            reasoningSettingsByModel: [
                'gpt-5.6-sol' => self::ReasoningSettings,
                'gpt-5.6-terra' => self::ReasoningSettings,
                'gpt-5.6-luna' => self::ReasoningSettings,
            ],
        );
    }

    public function execute(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
    ): NormalizedExecutionResult {
        $this->capabilities()->assertSupports(
            $agent,
            $this->identifier(),
        );

        $result = $this->runner->runForAgent(
            $project,
            $agent,
            $prompt,
            $onOutput,
            $onHeartbeat,
        );

        return new NormalizedExecutionResult(
            exitCode: $result['exit_code'],
            output: $result['output'],
            errorOutput: $result['error_output'],
        );
    }
}
