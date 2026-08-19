<?php

namespace App\Services;

final readonly class ContextBudgetDecision
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $prompt,
        public ?AssembledAgentContext $context,
        public array $evidence,
        public bool $blocked,
    ) {}
}

