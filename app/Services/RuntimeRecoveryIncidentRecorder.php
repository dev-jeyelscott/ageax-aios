<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoveryIncidentFamily;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RuntimeRecoveryIncidentRecorder
{
    private const int MaximumSourceLength = 255;

    private const int MaximumExceptionClassLength = 255;

    private const int MaximumFingerprintSummaryLength = 4096;

    public function __construct(
        private AuditLogger $audit,
        private SensitiveDataSanitizer $sanitizer,
    ) {}

    /**
     * Persist one runtime failure occurrence into the existing durable RecoveryIncident lifecycle.
     *
     * @param  array<string, mixed>|null  $evidence
     */
    public function record(
        RuntimeRecoveryIncidentFamily $family,
        string $source,
        ?string $exceptionClass,
        string $failureSummary,
        ?Project $project = null,
        ?Task $task = null,
        ?AgentWorker $agentWorker = null,
        ?AgentRun $sourceAgentRun = null,
        ?DateTimeInterface $occurredAt = null,
        ?array $evidence = null,
    ): RecoveryIncident {
        $scope = $this->resolveScope($project, $task, $agentWorker, $sourceAgentRun);
        $normalizedSource = $this->normalizeSource($source);
        $normalizedExceptionClass = $this->normalizeExceptionClass($exceptionClass);
        $sanitizedEvidence = $evidence === null
            ? null
            : $this->sanitizer->sanitizePayload($evidence);
        $fingerprint = $this->fingerprint(
            $family,
            $normalizedSource,
            $normalizedExceptionClass,
            $failureSummary,
        );
        $seenAt = $occurredAt === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($occurredAt);

        return DB::transaction(function () use (
            $family,
            $scope,
            $normalizedSource,
            $normalizedExceptionClass,
            $sanitizedEvidence,
            $fingerprint,
            $seenAt,
        ): RecoveryIncident {
            $this->lockDurableScope($scope['project'], $scope['task'], $scope['agent_worker'], $scope['source_agent_run']);

            $query = RecoveryIncident::query()
                ->where('failure_type', $family->value)
                ->where('fingerprint', $fingerprint)
                ->whereIn(
                    'status',
                    array_map(
                        static fn (RecoveryIncidentStatus $status): string => $status->value,
                        RecoveryIncidentStatus::open(),
                    ),
                );

            $scope['project'] === null
                ? $query->whereNull('project_id')
                : $query->where('project_id', $scope['project']->id);
            $scope['task'] === null
                ? $query->whereNull('task_id')
                : $query->where('task_id', $scope['task']->id);

            $incident = $query->orderBy('id')->lockForUpdate()->first();
            $newlyCreated = $incident === null;

            if ($incident === null) {
                $incident = RecoveryIncident::create([
                    'project_id' => $scope['project']?->id,
                    'task_id' => $scope['task']?->id,
                    'agent_worker_id' => $scope['agent_worker']?->id,
                    'source_agent_run_id' => $scope['source_agent_run']?->id,
                    'failure_type' => $family->value,
                    'fingerprint' => $fingerprint,
                    'source' => $normalizedSource,
                    'exception_class' => $normalizedExceptionClass,
                    'occurrence_count' => 1,
                    'status' => RecoveryIncidentStatus::Detected,
                    'detected_at' => $seenAt,
                    'first_seen_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                    'evidence' => $sanitizedEvidence,
                ]);
            } else {
                $lastSeenAt = $incident->last_seen_at;

                if ($lastSeenAt === null || $seenAt->greaterThan($lastSeenAt)) {
                    $lastSeenAt = $seenAt;
                }

                $incident->update([
                    'agent_worker_id' => $scope['agent_worker'] === null
                        ? $incident->agent_worker_id
                        : $scope['agent_worker']->id,
                    'source_agent_run_id' => $scope['source_agent_run'] === null
                        ? $incident->source_agent_run_id
                        : $scope['source_agent_run']->id,
                    'occurrence_count' => $incident->occurrence_count + 1,
                    'first_seen_at' => $incident->first_seen_at ?? $incident->detected_at,
                    'last_seen_at' => $lastSeenAt,
                ]);

                $incident = $incident->fresh();
            }

            $this->audit->record('recovery.runtime_occurrence_recorded', [
                'recovery_incident_id' => $incident->id,
                'failure_type' => $family->value,
                'fingerprint' => $fingerprint,
                'source' => $normalizedSource,
                'exception_class' => $normalizedExceptionClass,
                'occurrence_count' => $incident->occurrence_count,
                'project_id' => $scope['project']?->id,
                'task_id' => $scope['task']?->id,
                'agent_worker_id' => $scope['agent_worker']?->id,
                'source_agent_run_id' => $scope['source_agent_run']?->id,
                'newly_created' => $newlyCreated,
            ], $scope['project'], $scope['task']);

            return $incident->fresh();
        }, attempts: 3);
    }

    /**
     * Resolve and validate optional durable scope without allowing contradictory model references.
     *
     * @return array{project: ?Project, task: ?Task, agent_worker: ?AgentWorker, source_agent_run: ?AgentRun}
     */
    private function resolveScope(
        ?Project $project,
        ?Task $task,
        ?AgentWorker $agentWorker,
        ?AgentRun $sourceAgentRun,
    ): array {
        $taskId = $this->consistentId([
            $task?->id,
            $sourceAgentRun?->task_id,
        ], 'task');
        $agentWorkerId = $this->consistentId([
            $agentWorker?->id,
            $sourceAgentRun?->agent_worker_id,
        ], 'agent worker');
        $resolvedTask = $task ?? ($taskId === null ? null : Task::query()->findOrFail($taskId));
        $resolvedAgentWorker = $agentWorker ?? ($agentWorkerId === null ? null : AgentWorker::query()->findOrFail($agentWorkerId));
        $projectId = $this->consistentId([
            $project?->id,
            $resolvedTask?->project_id,
            $resolvedAgentWorker?->project_id,
            $sourceAgentRun?->project_id,
        ], 'project');

        return [
            'project' => $project ?? ($projectId === null ? null : Project::query()->findOrFail($projectId)),
            'task' => $resolvedTask,
            'agent_worker' => $resolvedAgentWorker,
            'source_agent_run' => $sourceAgentRun,
        ];
    }

    /**
     * Resolve exactly one nullable model identifier or fail closed when supplied scope disagrees.
     *
     * @param  array<int, int|null>  $ids
     */
    private function consistentId(array $ids, string $scopeName): ?int
    {
        $resolved = array_values(array_unique(array_filter(
            $ids,
            static fn (?int $id): bool => $id !== null,
        )));

        if (count($resolved) > 1) {
            throw new InvalidArgumentException("Runtime recovery {$scopeName} scope is inconsistent.");
        }

        return $resolved[0] ?? null;
    }

    /**
     * Serialize first-insert and repeat updates against the narrowest available durable scope row.
     */
    private function lockDurableScope(
        ?Project $project,
        ?Task $task,
        ?AgentWorker $agentWorker,
        ?AgentRun $sourceAgentRun,
    ): void {
        if ($task !== null) {
            Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            return;
        }

        if ($project !== null) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            return;
        }

        if ($agentWorker !== null) {
            AgentWorker::query()->whereKey($agentWorker->id)->lockForUpdate()->firstOrFail();

            return;
        }

        if ($sourceAgentRun !== null) {
            AgentRun::query()->whereKey($sourceAgentRun->id)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * Build a deterministic SHA-256 identity from sanitized, occurrence-stable failure material.
     */
    private function fingerprint(
        RuntimeRecoveryIncidentFamily $family,
        string $source,
        ?string $exceptionClass,
        string $failureSummary,
    ): string {
        $canonical = json_encode([
            'family' => $family->value,
            'source' => $source,
            'exception_class' => $exceptionClass,
            'failure' => $this->normalizeFingerprintText($failureSummary),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $canonical);
    }

    /**
     * Normalize and bound a stable route, command, or worker source identity before persistence.
     */
    private function normalizeSource(string $source): string
    {
        $normalized = $this->normalizeOccurrenceNoise($this->sanitizer->sanitizeText($source));
        $normalized = Str::substr(Str::squish($normalized), 0, self::MaximumSourceLength);

        if ($normalized === '') {
            throw new InvalidArgumentException('Runtime recovery source must not be empty.');
        }

        return $normalized;
    }

    /**
     * Normalize and bound an optional exception class without persisting arbitrary exception text.
     */
    private function normalizeExceptionClass(?string $exceptionClass): ?string
    {
        if ($exceptionClass === null) {
            return null;
        }

        $normalized = Str::substr(Str::squish(ltrim($exceptionClass, '\\')), 0, self::MaximumExceptionClassLength);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Sanitize volatile and secret-bearing failure text used only as ephemeral fingerprint input.
     */
    private function normalizeFingerprintText(string $failureSummary): string
    {
        $normalized = $this->sanitizer->sanitizeText($failureSummary);
        $normalized = $this->normalizeOccurrenceNoise($normalized);
        $normalized = Str::substr(Str::squish($normalized), 0, self::MaximumFingerprintSummaryLength);

        if ($normalized === '') {
            throw new InvalidArgumentException('Runtime recovery failure summary must not be empty.');
        }

        return $normalized;
    }

    /**
     * Replace known occurrence-random identifiers while preserving meaningful numeric error codes.
     */
    private function normalizeOccurrenceNoise(string $text): string
    {
        $normalized = preg_replace(
            '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?\b/i',
            '[TIMESTAMP]',
            $text,
        ) ?? $text;

        $normalized = preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',
            '[UUID]',
            $normalized,
        ) ?? $normalized;

        $normalized = preg_replace(
            '/\b[0-9A-HJKMNP-TV-Z]{26}\b/i',
            '[ULID]',
            $normalized,
        ) ?? $normalized;

        return preg_replace(
            '/(?i)\b((?:request|correlation|trace)[_-]?id)\s*(?::|=|\s)\s*[^\s,;]+/',
            '$1=[ID]',
            $normalized,
        ) ?? $normalized;
    }
}
