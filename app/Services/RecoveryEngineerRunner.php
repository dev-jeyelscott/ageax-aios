<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;

/**
 * Dispatches the Workflow Recovery Engineer's fresh, disposable diagnosis/repair execution.
 *
 * The Recovery Engineer is an AIOS system role, configured as a global Agent (project_id null)
 * rather than a project Agent. The caller resolves and validates that persisted Agent (via
 * GlobalAgentResolver / AgentHarnessResolver) and supplies it here. The caller also supplies the
 * execution path explicitly: it must always be a disposable RecoveryWorktreeManager worktree, never
 * the live AIOS checkout (config('aios.recovery_repository_path')) directly, so neither harness is
 * ever granted Edit/Write/Bash access to AIOS's own repository or database.
 */
class RecoveryEngineerRunner
{
    public function __construct(
        private ClaudeCodeCliRunner $claudeCodeRunner,
        private CodexCliRunner $codexRunner,
        private StructuredResultParser $parser,
    ) {}

    /** @return array{execution: array{exit_code: int, output: string, error_output: string}, decision: ?array<string, mixed>} */
    public function run(Agent $agent, string $prompt, string $path): array
    {
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
}
