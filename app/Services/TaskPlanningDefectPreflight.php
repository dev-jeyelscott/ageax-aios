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

    public function __construct(
        private TaskValidator $validator,
        private WorkspacePathResolver $paths,
    ) {}

    /** @return array{type: string, fingerprint: string, evidence: array<string, mixed>, allowed_fields: list<string>}|null */
    public function evaluate(Task $task): ?array
    {
        $rawCommands = $task->getAttribute('verification_commands') ?? [];
        $commands = $this->stringList($rawCommands);
        if ($commands === null || ! $this->verificationCommandsAreSafe($commands)) {
            return $this->defect('unsafe_verification_commands', ['verification_commands' => $rawCommands], ['verification_commands']);
        }

        if (($missingVerificationFile = $this->missingVerificationFile($task, $commands)) !== null) {
            return $this->defect('missing_verification_file', $missingVerificationFile, ['verification_commands']);
        }

        $paths = $task->relevant_paths ?? [];
        if (! is_array($paths) || collect($paths)->contains(fn (mixed $path): bool => ! is_string($path) || ! $this->safeRelativePath($path))) {
            return $this->defect('unsafe_relevant_paths', ['relevant_paths' => $paths], ['relevant_paths']);
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

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $allowedFields
     * @return array{type: string, fingerprint: string, evidence: array<string, mixed>, allowed_fields: list<string>}
     */
    private function defect(string $type, array $evidence, array $allowedFields): array
    {
        return ['type' => $type, 'fingerprint' => hash('sha256', json_encode([$type, $evidence], JSON_THROW_ON_ERROR)), 'evidence' => $evidence, 'allowed_fields' => $allowedFields];
    }

    private function safeRelativePath(string $path): bool
    {
        return $path !== '' && ! Str::startsWith($path, ['/', '\\']) && ! str_contains($path, '\\') && ! collect(explode('/', $path))->contains('..');
    }

    /** @param list<string> $commands */
    private function verificationCommandsAreSafe(array $commands): bool
    {
        return $commands === [] || $this->validator->verificationCommandsAreSafe($commands);
    }

    /**
     * @param  list<string>  $commands
     * @return array{command: string, path: string}|null
     */
    private function missingVerificationFile(Task $task, array $commands): ?array
    {
        foreach ($commands as $command) {
            foreach (preg_split('/\s+/', trim($command)) ?: [] as $token) {
                if (! Str::startsWith($token, 'tests/') || ! Str::endsWith($token, '.php')) {
                    continue;
                }

                $projectPath = rtrim($this->paths->assertProjectPath($task->project->path), DIRECTORY_SEPARATOR);
                $resolvedProjectPath = realpath($projectPath);
                if ($resolvedProjectPath === false) {
                    return ['command' => $command, 'path' => $token];
                }

                $resolvedFile = realpath($resolvedProjectPath.DIRECTORY_SEPARATOR.$token);
                if ($resolvedFile === false
                    || ! Str::startsWith($resolvedFile, $resolvedProjectPath.DIRECTORY_SEPARATOR)
                    || ! is_file($resolvedFile)) {
                    return ['command' => $command, 'path' => $token];
                }
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private function stringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
