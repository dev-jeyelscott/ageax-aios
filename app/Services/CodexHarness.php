<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use App\Models\Project;
use Closure;

final readonly class CodexHarness implements AgentHarness
{
    public function __construct(private CodexCliRunner $runner) {}

    public function identifier(): AgentHarnessIdentifier
    {
        return AgentHarnessIdentifier::Codex;
    }

    public function capabilities(): HarnessCapabilities
    {
        return new HarnessCapabilities(
            models: [],
            reasoningSettings: [],
            executionOptions: ['ephemeral', 'streaming', 'heartbeat'],
        );
    }

    /**
     * $agent is accepted to satisfy the AgentHarness contract. CodexCliRunner
     * has no per-agent Codex CLI configuration today, so it is not forwarded.
     */
    public function execute(Project $project, Agent $agent, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null): NormalizedExecutionResult
    {
        $result = $this->runner->run($project, $prompt, $onOutput, $onHeartbeat);

        return new NormalizedExecutionResult(
            exitCode: $result['exit_code'],
            output: $result['output'],
            errorOutput: $result['error_output'],
        );
    }
}
