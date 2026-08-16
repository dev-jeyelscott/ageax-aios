<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Models\Agent;

/**
 * Dispatches the Workflow Recovery Engineer's fresh, disposable diagnosis/repair execution.
 *
 * The Recovery Engineer is an AIOS system role, not a project Agent (Agent::role rejects
 * AgentRole::RecoveryEngineer), so it is never persisted: the Agent instance built here exists
 * only to reuse the existing harness dispatch contract (model/reasoning configuration, tool
 * gating) and is discarded once the run completes.
 */
class RecoveryEngineerRunner
{
    public function __construct(
        private ClaudeCodeCliRunner $claudeCodeRunner,
        private CodexCliRunner $codexRunner,
        private StructuredResultParser $parser,
    ) {}

    /** @return array{execution: array{exit_code: int, output: string, error_output: string}, decision: ?array<string, mixed>} */
    public function run(string $prompt): array
    {
        $agent = $this->transientAgent();
        $path = (string) config('aios.recovery_repository_path');

        $execution = $agent->getRawOriginal('harness') === AgentHarnessIdentifier::Codex->value
            ? $this->codexRunner->runAtPath($path, $prompt, null, null, $agent)
            : $this->normalize($this->claudeCodeRunner->runAtPath($path, $agent, $prompt));

        return [
            'execution' => $execution,
            'decision' => $this->parser->parseAgentMessage($execution['output']),
        ];
    }

    /**
     * @param  array{exit_code: int, output: string, error_output: string, failure_type: ?string}  $execution
     * @return array{exit_code: int, output: string, error_output: string}
     */
    private function normalize(array $execution): array
    {
        return [
            'exit_code' => $execution['exit_code'],
            'output' => $execution['output'],
            'error_output' => $execution['error_output'],
        ];
    }

    private function transientAgent(): Agent
    {
        $agent = new Agent;
        $agent->forceFill([
            'name' => 'AIOS Workflow Recovery Engineer',
            'role' => AgentRole::RecoveryEngineer,
            'harness' => AgentHarnessIdentifier::from((string) config('aios.recovery_engineer_harness')),
            'model' => config('aios.recovery_engineer_model'),
            'reasoning_setting' => config('aios.recovery_engineer_reasoning_setting'),
            'enabled' => true,
        ]);

        return $agent;
    }
}
