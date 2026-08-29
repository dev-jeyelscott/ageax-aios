<?php

namespace App\Contracts\Context;

use App\AgentRole;

/**
 * Provider-independent request for one Agent execution context. Carries project identity,
 * logical Agent identity, and the workflow role/task context already assembled by
 * AgentContextAssembler, without any Codex/Claude Code or workflow-role coupling.
 */
final readonly class ContextRequest
{
    /** @param array<string, mixed> $taskContext */
    public function __construct(
        public int $projectId,
        public int $agentId,
        public AgentRole $role,
        public array $taskContext,
    ) {}
}
