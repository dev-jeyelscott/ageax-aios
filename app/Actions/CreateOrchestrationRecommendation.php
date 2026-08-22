<?php

namespace App\Actions;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\OrchestrationRecommendation;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\OrchestrationRecommendationStatus;
use App\OrchestrationRecommendationType;
use App\Services\AuditLogger;
use App\Services\OrchestrationRecommendationSchemaValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

class CreateOrchestrationRecommendation
{
    public function __construct(
        private OrchestrationRecommendationSchemaValidator $schemaValidator,
        private AuditLogger $audit,
    ) {}

    /**
     * Validate and persist one immutable advisory recommendation from a completed Orchestrator run.
     *
     * @param  array<string, mixed>  $structuredRecommendation
     */
    public function handle(
        AgentRun $agentRun,
        OrchestrationRecommendationType $type,
        int $schemaVersion,
        int|float|string $confidence,
        array $structuredRecommendation,
        ?Project $project = null,
        ?Task $task = null,
        ?RecoveryIncident $recoveryIncident = null,
    ): OrchestrationRecommendation {
        return DB::transaction(function () use (
            $agentRun,
            $type,
            $schemaVersion,
            $confidence,
            $structuredRecommendation,
            $project,
            $task,
            $recoveryIncident,
        ): OrchestrationRecommendation {
            $run = AgentRun::query()
                ->whereKey($agentRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEligibleSourceRun($run);

            $projectId = $this->validatedScopeProjectId(
                $run,
                $project,
                $task,
                $recoveryIncident,
            );

            $validatedRecommendation = $this->schemaValidator->validate(
                $type,
                $schemaVersion,
                $structuredRecommendation,
            );

            $normalizedConfidence = $this->normalizeConfidence($confidence);
            $evidenceHash = $this->evidenceHash($run);

            $recommendation = OrchestrationRecommendation::create([
                'project_id' => $projectId,
                'task_id' => $task?->id,
                'recovery_incident_id' => $recoveryIncident?->id,
                'agent_run_id' => $run->id,
                'recommendation_type' => $type,
                'schema_version' => $schemaVersion,
                'evidence_hash' => $evidenceHash,
                'confidence' => $normalizedConfidence,
                'structured_recommendation' => $validatedRecommendation,
                'status' => OrchestrationRecommendationStatus::Active,
            ]);

            $auditProject = $projectId === null
                ? null
                : Project::query()->findOrFail($projectId);

            $this->audit->record('orchestrator.recommendation_created', [
                'recommendation_id' => $recommendation->id,
                'agent_run_id' => $run->id,
                'recommendation_type' => $type->value,
                'schema_version' => $schemaVersion,
                'confidence' => $normalizedConfidence,
                'evidence_hash' => $evidenceHash,
                'project_id' => $projectId,
                'task_id' => $task?->id,
                'recovery_incident_id' => $recoveryIncident?->id,
            ], $auditProject, $task);

            return $recommendation;
        }, attempts: 3);
    }

    /**
     * Prove the source run is a completed, workerless Global Orchestrator execution
     * with internally consistent immutable configuration evidence.
     */
    private function assertEligibleSourceRun(AgentRun $run): void
    {
        $role = $run->getRawOriginal('role');

        if (! is_string($role) || $role !== AgentRole::Orchestrator->value) {
            throw new LogicException(
                'Only an Orchestrator AgentRun may produce an orchestration recommendation.',
            );
        }

        $status = $run->getRawOriginal('status');

        if (! is_string($status)
            || $status !== AgentRunStatus::Completed->value
            || $run->finished_at === null) {
            throw new LogicException(
                'An orchestration recommendation requires a completed Orchestrator AgentRun.',
            );
        }

        if ($run->agent_worker_id !== null) {
            throw new LogicException(
                'A Global Orchestrator AgentRun must not be attached to an AgentWorker.',
            );
        }

        if ($run->agent_id === null) {
            throw new LogicException(
                'The Orchestrator AgentRun is missing its durable Agent identity.',
            );
        }

        $snapshot = $run->getAttribute('configuration_snapshot');

        if (! is_array($snapshot)) {
            throw new LogicException(
                'The Orchestrator AgentRun is missing valid immutable configuration evidence.',
            );
        }

        $agentSnapshot = $snapshot['agent'] ?? null;

        if (! is_array($agentSnapshot)
            || ($agentSnapshot['id'] ?? null) !== $run->agent_id
            || ($agentSnapshot['role'] ?? null) !== AgentRole::Orchestrator->value
            || ($snapshot['context_schema_version'] ?? null) !== $run->context_schema_version) {
            throw new LogicException(
                'The Orchestrator AgentRun is missing valid immutable configuration evidence.',
            );
        }

        $this->evaluatedEvidenceHashes($run);
    }

    /**
     * Resolve and validate project, task, and recovery scope without allowing cross-project evidence.
     */
    private function validatedScopeProjectId(
        AgentRun $run,
        ?Project $project,
        ?Task $task,
        ?RecoveryIncident $recoveryIncident,
    ): ?int {
        if ($project !== null && ! $project->exists) {
            throw new LogicException(
                'Recommendation project scope must be persisted.',
            );
        }

        if ($task !== null && ! $task->exists) {
            throw new LogicException(
                'Recommendation task scope must be persisted.',
            );
        }

        if ($recoveryIncident !== null && ! $recoveryIncident->exists) {
            throw new LogicException(
                'Recommendation recovery scope must be persisted.',
            );
        }

        $runProjectId = (int) $run->project_id;
        $projectId = $project === null ? null : (int) $project->id;
        $taskProjectId = $task === null ? null : (int) $task->project_id;
        $recoveryProjectId = $recoveryIncident?->project_id === null
            ? null
            : (int) $recoveryIncident->project_id;

        $recoveryTaskProjectId = null;

        if ($recoveryIncident?->task_id !== null) {
            $value = Task::query()
                ->whereKey($recoveryIncident->task_id)
                ->value('project_id');

            $recoveryTaskProjectId = $value === null
                ? null
                : (int) $value;
        }

        foreach ([
            $projectId,
            $taskProjectId,
            $recoveryProjectId,
            $recoveryTaskProjectId,
        ] as $scopeProjectId) {
            if ($scopeProjectId !== null && $scopeProjectId !== $runProjectId) {
                throw new LogicException(
                    'Recommendation scope cannot cross the source AgentRun project boundary.',
                );
            }
        }

        if ($task !== null
            && $recoveryIncident?->task_id !== null
            && (int) $recoveryIncident->task_id !== (int) $task->id) {
            throw new LogicException(
                'Recommendation Task and RecoveryIncident scope must refer to the same Task.',
            );
        }

        return $projectId
            ?? $taskProjectId
            ?? $recoveryProjectId
            ?? $recoveryTaskProjectId;
    }

    /**
     * Validate confidence and normalize it for deterministic DECIMAL persistence.
     */
    private function normalizeConfidence(int|float|string $confidence): string
    {
        $validated = Validator::make(
            ['confidence' => $confidence],
            [
                'confidence' => [
                    'required',
                    'numeric',
                    'between:0,1',
                ],
            ],
        )->validate();

        return number_format(
            (float) $validated['confidence'],
            4,
            '.',
            '',
        );
    }

    /**
     * Hash the exact persisted context and prompt identities evaluated by the run.
     */
    private function evidenceHash(AgentRun $run): string
    {
        $evidence = $this->evaluatedEvidenceHashes($run);

        return hash(
            'sha256',
            json_encode(
                [
                    'context_schema_version' => $run->context_schema_version,
                    'context_hash' => $evidence['context_hash'],
                    'prompt_hash' => $evidence['prompt_hash'],
                    'context_budget_schema_version' => $evidence['context_budget_schema_version'],
                ],
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /**
     * Resolve hashes representing the actual provider-facing evidence.
     *
     * Prefer Context Budget final hashes because deterministic reduction may have changed
     * the provider-facing context after the original AgentRun snapshot was created.
     *
     * @return array{
     *     context_hash: string,
     *     prompt_hash: string,
     *     context_budget_schema_version: int|null
     * }
     */
    private function evaluatedEvidenceHashes(AgentRun $run): array
    {
        $budget = $run->getAttribute('context_budget_snapshot');

        if ($budget !== null) {
            if (! is_array($budget)) {
                throw new LogicException(
                    'The Orchestrator AgentRun has invalid final Context Budget evidence.',
                );
            }

            $contextHash = $budget['final_context_hash'] ?? null;
            $promptHash = $budget['final_prompt_hash'] ?? null;
            $budgetSchemaVersion = $run->context_budget_schema_version;

            if (! in_array($budget['decision'] ?? null, ['approved', 'reduced'], true)
                || ! is_int($budgetSchemaVersion)
                || $budgetSchemaVersion < 1
                || ($budget['schema_version'] ?? null) !== $budgetSchemaVersion
                || ! is_string($contextHash)
                || ! $this->isSha256($contextHash)
                || ! is_string($promptHash)
                || ! $this->isSha256($promptHash)) {
                throw new LogicException(
                    'The Orchestrator AgentRun has invalid final Context Budget evidence.',
                );
            }

            return [
                'context_hash' => $contextHash,
                'prompt_hash' => $promptHash,
                'context_budget_schema_version' => $budgetSchemaVersion,
            ];
        }

        $snapshot = $run->getAttribute('configuration_snapshot');

        if (! is_array($snapshot)) {
            throw new LogicException(
                'The Orchestrator AgentRun is missing valid immutable evidence hashes.',
            );
        }

        $contextHash = $snapshot['context_hash'] ?? null;
        $promptHash = $run->getAttribute('prompt_hash');

        if (! is_string($contextHash)
            || ! $this->isSha256($contextHash)
            || ! is_string($promptHash)
            || ! $this->isSha256($promptHash)) {
            throw new LogicException(
                'The Orchestrator AgentRun is missing valid immutable evidence hashes.',
            );
        }

        return [
            'context_hash' => $contextHash,
            'prompt_hash' => $promptHash,
            'context_budget_schema_version' => null,
        ];
    }

    /**
     * Determine whether a string is a canonical 64-character SHA-256 hexadecimal digest.
     */
    private function isSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
