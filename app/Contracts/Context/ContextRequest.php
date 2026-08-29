<?php

namespace App\Contracts\Context;

/**
 * Provider-independent request for one Agent execution context. Carries project identity,
 * logical Agent identity, and the already-resolved task context, without any Codex/Claude Code
 * or workflow-role coupling. Callers resolve any workflow role internally before assembling the
 * task context passed here.
 */
final readonly class ContextRequest
{
    /** @param array<string, mixed> $taskContext */
    public function __construct(
        public int $projectId,
        public int $agentId,
        public array $taskContext,
    ) {}
}
