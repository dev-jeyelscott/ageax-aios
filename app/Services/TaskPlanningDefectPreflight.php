<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Str;

/** Deterministic, harness-free Task contract checks eligible for PM revision. */
class TaskPlanningDefectPreflight
{
    public const array AllowedFields = [
        'acceptance_criteria', 'scope', 'constraints', 'relevant_paths',
        'verification_commands', 'implementation_prompt', 'dependencies',
    ];

    public function __construct(private TaskValidator $validator) {}

    /** @return array{type: string, fingerprint: string, evidence: array<string, mixed>, allowed_fields: list<string>}|null */
    public function evaluate(Task $task): ?array
    {
        $commands = $task->verification_commands ?? [];
        if (! is_array($commands) || collect($commands)->contains(fn (mixed $command): bool => ! is_string($command)) || ! $this->validator->verificationCommandsAreSafe($commands)) {
            return $this->defect('unsafe_verification_commands', ['verification_commands' => is_array($commands) ? array_values($commands) : []], ['verification_commands']);
        }

        $paths = $task->relevant_paths ?? [];
        if (! is_array($paths) || collect($paths)->contains(fn (mixed $path): bool => ! is_string($path) || ! $this->safeRelativePath($path))) {
            return $this->defect('unsafe_relevant_paths', ['relevant_paths' => is_array($paths) ? array_values($paths) : []], ['relevant_paths']);
        }

        // A persistent worker can retain a Task instance after an operator corrects phase
        // placement. Dependency validity must use the current persisted phase rather than an
        // already-loaded Eloquent relation from an earlier scheduler cycle.
        $taskPhase = $task->phase()->first();
        $invalidDependency = $task->dependencies()->with('phase')->get()->first(fn (Task $dependency): bool => $dependency->project_id !== $task->project_id
            || $dependency->id === $task->id
            || $dependency->position >= $task->position
            || ($taskPhase !== null && $dependency->phase !== null && $dependency->phase->position > $taskPhase->position));
        if ($invalidDependency !== null) {
            return $this->defect('invalid_dependency_placement', ['dependency_key' => $invalidDependency->key, 'dependency_position' => $invalidDependency->position], ['dependencies']);
        }

        return null;
    }

    /** @param array<string, mixed> $evidence @param list<string> $allowedFields @return array{type: string, fingerprint: string, evidence: array<string,mixed>, allowed_fields: list<string>} */
    private function defect(string $type, array $evidence, array $allowedFields): array
    {
        return ['type' => $type, 'fingerprint' => hash('sha256', json_encode([$type, $evidence], JSON_THROW_ON_ERROR)), 'evidence' => $evidence, 'allowed_fields' => $allowedFields];
    }

    private function safeRelativePath(string $path): bool
    {
        return $path !== '' && ! Str::startsWith($path, ['/', '\\']) && ! str_contains($path, '\\') && ! collect(explode('/', $path))->contains('..');
    }
}
