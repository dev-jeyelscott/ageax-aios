<?php

namespace App\Actions;

use App\AgentHandoffStatus;
use App\AgentHandoffType;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentHandoffSchemaValidator;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateAgentHandoff
{
    /**
     * Inject AIOS-owned schema validation and audit persistence.
     */
    public function __construct(
        private AgentHandoffSchemaValidator $schemas,
        private AuditLogger $audit,
    ) {}

    /**
     * Validate and persist one durable project-scoped Agent handoff.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        AgentRun $agentRun,
        AgentRole|string $toRole,
        AgentHandoffType|string $handoffType,
        int $schemaVersion,
        array $payload,
    ): AgentHandoff {
        $targetRole = $this->normalizeTargetRole($toRole);
        $type = $this->normalizeHandoffType($handoffType);

        return DB::transaction(function () use (
            $agentRun,
            $targetRole,
            $type,
            $schemaVersion,
            $payload,
        ): AgentHandoff {
            $run = AgentRun::query()
                ->whereKey($agentRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            [$project, $task, $fromRole]
                = $this->validatedSourceScope($run);

            $validatedPayload = $this->schemas->validate(
                $type,
                $schemaVersion,
                $payload,
            );

            $canonicalPayload = $this->canonicalPayload(
                $validatedPayload,
            );

            $contentHash = $this->contentHash(
                $project,
                $task,
                $run,
                $fromRole,
                $targetRole,
                $type,
                $schemaVersion,
                $canonicalPayload,
            );

            $existing = AgentHandoff::query()
                ->where('project_id', $project->id)
                ->where('content_hash', $contentHash)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $handoff = AgentHandoff::create([
                'project_id' => $project->id,
                'task_id' => $task?->id,
                'from_agent_run_id' => $run->id,
                'from_role' => $fromRole->value,
                'to_role' => $targetRole->value,
                'handoff_type' => $type->value,
                'schema_version' => $schemaVersion,
                'payload' => $canonicalPayload,
                'content_hash' => $contentHash,
                'status' => AgentHandoffStatus::Pending->value,
            ]);

            $this->audit->record(
                'agent_handoff.created',
                [
                    'agent_handoff_id' => $handoff->id,
                    'from_agent_run_id' => $run->id,
                    'from_role' => $fromRole->value,
                    'to_role' => $targetRole->value,
                    'handoff_type' => $type->value,
                    'schema_version' => $schemaVersion,
                    'content_hash' => $contentHash,
                    'project_id' => $project->id,
                    'task_id' => $task?->id,
                ],
                $project,
                $task,
            );

            return $handoff;
        }, attempts: 3);
    }

    /**
     * Validate a target role against the current AgentRole domain.
     */
    private function normalizeTargetRole(
        AgentRole|string $role,
    ): AgentRole {
        if ($role instanceof AgentRole) {
            return $role;
        }

        $normalized = AgentRole::tryFrom($role);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'to_role' => "Unsupported Agent handoff target role [{$role}].",
            ]);
        }

        return $normalized;
    }

    /**
     * Validate a requested handoff type against the approved collaboration domain.
     */
    private function normalizeHandoffType(
        AgentHandoffType|string $type,
    ): AgentHandoffType {
        if ($type instanceof AgentHandoffType) {
            return $type;
        }

        $normalized = AgentHandoffType::tryFrom($type);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'handoff_type' => "Unsupported Agent handoff type [{$type}].",
            ]);
        }

        return $normalized;
    }

    /**
     * Prove source-run project, task, role, and completed-execution scope.
     *
     * @return array{0: Project, 1: Task|null, 2: AgentRole}
     */
    private function validatedSourceScope(
        AgentRun $run,
    ): array {
        if ($run->project_id === null) {
            throw new LogicException(
                'Agent handoffs require a provable project-scoped source AgentRun.',
            );
        }

        $project = Project::query()->find(
            (int) $run->project_id,
        );

        if ($project === null) {
            throw new LogicException(
                'Agent handoff source project no longer exists.',
            );
        }

        if (
            $run->getRawOriginal('status')
                !== AgentRunStatus::Completed->value
            || $run->finished_at === null
        ) {
            throw new LogicException(
                'Agent handoffs require a completed source AgentRun.',
            );
        }

        $rawRole = $run->getRawOriginal('role');

        $fromRole = is_string($rawRole)
            ? AgentRole::tryFrom($rawRole)
            : null;

        if ($fromRole === null) {
            throw new LogicException(
                'Agent handoff source AgentRun has an invalid durable role.',
            );
        }

        $task = null;

        if ($run->task_id !== null) {
            $task = Task::query()->find(
                (int) $run->task_id,
            );

            if ($task === null) {
                throw new LogicException(
                    'Agent handoff source Task no longer exists.',
                );
            }

            if (
                (int) $task->project_id
                !== (int) $project->id
            ) {
                throw new LogicException(
                    'Agent handoff source Task cannot cross the source AgentRun project boundary.',
                );
            }
        }

        return [
            $project,
            $task,
            $fromRole,
        ];
    }

    /**
     * Canonicalize associative keys recursively while preserving list ordering.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function canonicalPayload(array $payload): array
    {
        $canonical = $this->canonicalizeValue($payload);

        if (! is_array($canonical)) {
            throw new LogicException(
                'Agent handoff payload canonicalization failed.',
            );
        }

        return $canonical;
    }

    /**
     * Canonicalize one nested value for deterministic hashing.
     */
    private function canonicalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalizeValue($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeValue($item);
        }

        return $value;
    }

    /**
     * Calculate the deterministic fingerprint of the persisted handoff envelope.
     *
     * @param  array<string, mixed>  $payload
     */
    private function contentHash(
        Project $project,
        ?Task $task,
        AgentRun $run,
        AgentRole $fromRole,
        AgentRole $toRole,
        AgentHandoffType $type,
        int $schemaVersion,
        array $payload,
    ): string {
        return hash(
            'sha256',
            json_encode(
                [
                    'project_id' => $project->id,
                    'task_id' => $task?->id,
                    'from_agent_run_id' => $run->id,
                    'from_role' => $fromRole->value,
                    'to_role' => $toRole->value,
                    'handoff_type' => $type->value,
                    'schema_version' => $schemaVersion,
                    'payload' => $payload,
                ],
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
