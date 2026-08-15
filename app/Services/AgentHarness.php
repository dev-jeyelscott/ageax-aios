<?php

namespace App\Services;

use App\AgentHarness as AgentHarnessIdentifier;
use App\Models\Agent;
use App\Models\Project;
use Closure;

interface AgentHarness
{
    public function identifier(): AgentHarnessIdentifier;

    public function capabilities(): HarnessCapabilities;

    /**
     * @param  (Closure(string, string): void)|null  $onOutput
     * @param  (Closure(): mixed)|null  $onHeartbeat
     */
    public function execute(Project $project, Agent $agent, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null): NormalizedExecutionResult;
}
