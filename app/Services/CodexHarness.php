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

    private const int ConservativeDefaultContextWindowTokens = 200000;

    private const int ConservativeDefaultMaxOutputTokens = 64000;

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
            contextWindowTokensByModel: [
                'gpt-5.6-sol' => 1050000,
                'gpt-5.6-terra' => 1050000,
                'gpt-5.6-luna' => 1050000,
            ],
            maxOutputTokensByModel: [
                'gpt-5.6-sol' => 128000,
                'gpt-5.6-terra' => 128000,
                'gpt-5.6-luna' => 128000,
            ],
            defaultContextWindowTokens: self::ConservativeDefaultContextWindowTokens,
            defaultMaxOutputTokens: self::ConservativeDefaultMaxOutputTokens,
            capacityMetadataSource: 'openai_model_docs_2026_08_19',
            capacityMetadataVersion: 1,
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

