<?php

namespace App\Contracts\Context;

/**
 * Provider-independent identity evidence produced by resolving a standalone repository directory
 * or an explicit Project reference to exactly one registered AIOS Project. Carries only the
 * resolved Project identity and the precedence tier that matched; it grants no workflow authority
 * and exposes no secrets or raw repository content.
 */
final readonly class ProjectResolution
{
    public function __construct(
        public int $projectId,
        public ProjectResolutionMethod $method,
    ) {}

    /** @return array{project_id: int, method: string} */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'method' => $this->method->value,
        ];
    }
}
