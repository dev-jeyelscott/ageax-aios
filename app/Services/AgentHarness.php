<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use App\Models\Project;
use Closure;

interface AgentHarness
{
    /**
     * Return the persisted harness identifier implemented by this adapter.
     */
    public function identifier(): AgentHarnessIdentifier;

    /**
     * Return the validated provider capabilities used by AIOS-owned configuration and Context Budget policy.
     */
    public function capabilities(): HarnessCapabilities;

    /**
     * Execute one AIOS-authorized Agent run, optionally inside an AIOS-selected workspace path.
     *
     * The execution path is orchestration input only. Agents and provider harnesses never choose it.
     *
     * @param  (Closure(string, string): void)|null  $onOutput
     * @param  (Closure(): mixed)|null  $onHeartbeat
     * @param  array<string, mixed>  $executionSettings
     */
    public function execute(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        array $executionSettings = [],
        ?string $executionPath = null,
    ): NormalizedExecutionResult;
}
