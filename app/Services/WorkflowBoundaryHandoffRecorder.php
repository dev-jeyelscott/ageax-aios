<?php

namespace App\Services;

use App\Actions\CreateAgentHandoff;
use App\AgentHandoffType;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\RecoveryIncident;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\RecoveryIncidentStatus;
use App\ReviewStatus;
use App\TaskStatus;
use Illuminate\Support\Str;
use Throwable;

/**
 * Derives typed handoff evidence only after authoritative AIOS workflow boundaries are complete.
 *
 * The existing CreateAgentHandoff Action remains the sole handoff persistence boundary. This
 * helper never claims work, changes workflow state, schedules execution, or consumes handoffs.
 */
final class WorkflowBoundaryHandoffRecorder
{
    /**
     * Inject the existing P8-001 handoff Action and audit logger used for failure evidence.
     */
    public function __construct(
        private CreateAgentHandoff $handoffs,
        private AuditLogger $audit,
    ) {}

    /**
     * Record one Coder-to-Reviewer implementation handoff after AIOS reaches ready_for_review.
     */
    public function recordImplementationReady(
        Task $task,
        TaskAttempt $attempt,
        AgentRun $sourceRun,
    ): ?AgentHandoff {
        try {
            $task = $task->fresh(['project']) ?? $task;
            $attempt = $attempt->fresh() ?? $attempt;
            $sourceRun = $sourceRun->fresh() ?? $sourceRun;
            $validation = $attempt->getAttribute('validation_results');
            $rawChangedFiles = $attempt->getAttribute('changed_files');

            if (
                TaskStatus::from((string) $task->getRawOriginal('status'))
                    !== TaskStatus::ReadyForReview
                || $attempt->getRawOriginal('status') !== 'completed'
                || ! is_array($validation)
                || ! $this->implementationValidationSupportsReview(
                    $validation,
                )
                || ! is_array($rawChangedFiles)
                || ($rawChangedFiles !== [] && blank($attempt->commit_sha))
                || ! $this->sourceRunMatches(
                    $sourceRun,
                    $task,
                    $attempt,
                    AgentRole::Coder,
                )
            ) {
                return null;
            }

            $changedFiles = $this->boundedStringList(
                $rawChangedFiles,
                100,
                512,
            );

            $summary = $changedFiles === []
                ? "Task {$task->key} attempt #{$attempt->number} required no repository changes and passed deterministic AIOS validation before reaching review."
                : "Task {$task->key} attempt #{$attempt->number} passed deterministic AIOS validation, committed the validated change set, and reached review.";

            return $this->handoffs->handle(
                $sourceRun,
                AgentRole::Reviewer,
                AgentHandoffType::ImplementationHandoff,
                AgentHandoffSchemaValidator::SchemaVersion,
                $this->implementationPayload(
                    $summary,
                    $changedFiles,
                    $validation,
                ),
            );
        } catch (Throwable $throwable) {
            $this->recordFailure(
                $throwable,
                $sourceRun,
                $task,
                AgentRole::Reviewer,
                AgentHandoffType::ImplementationHandoff,
            );

            return null;
        }
    }

    /**
     * Record one Reviewer-to-Coder finding handoff after AIOS finalizes changes_required.
     */
    public function recordReviewFinding(
        Task $task,
        TaskAttempt $attempt,
        Review $review,
        AgentRun $sourceRun,
    ): ?AgentHandoff {
        try {
            $task = $task->fresh(['project']) ?? $task;
            $attempt = $attempt->fresh() ?? $attempt;
            $review = $review->fresh('findings') ?? $review;
            $sourceRun = $sourceRun->fresh() ?? $sourceRun;

            if (
                TaskStatus::from((string) $task->getRawOriginal('status'))
                    !== TaskStatus::ChangesRequired
                || $attempt->getRawOriginal('status') !== 'completed'
                || (int) $review->task_id !== (int) $task->id
                || (int) $review->task_attempt_id !== (int) $attempt->id
                || $review->getRawOriginal('status')
                    !== ReviewStatus::ChangesRequired->value
                || $review->getRawOriginal('completed_at') === null
                || $review->findings->isEmpty()
                || ! $this->sourceRunMatches(
                    $sourceRun,
                    $task,
                    $attempt,
                    AgentRole::Reviewer,
                )
            ) {
                return null;
            }

            return $this->handoffs->handle(
                $sourceRun,
                AgentRole::Coder,
                AgentHandoffType::ReviewFinding,
                AgentHandoffSchemaValidator::SchemaVersion,
                [
                    'summary' => $this->boundedNullableText(
                        is_string($review->summary) ? $review->summary : null,
                        4000,
                    ),
                    'findings' => $review->findings
                        ->take(20)
                        ->map(
                            fn (ReviewFinding $finding): array => $this->reviewFindingPayload($finding),
                        )
                        ->values()
                        ->all(),
                ],
            );
        } catch (Throwable $throwable) {
            $this->recordFailure(
                $throwable,
                $sourceRun,
                $task,
                AgentRole::Coder,
                AgentHandoffType::ReviewFinding,
            );

            return null;
        }
    }

    /**
     * Record one Recovery Engineer-to-Coder advice handoff after AIOS accepts workflow recovery.
     */
    public function recordRecoveryAdvice(
        RecoveryIncident $incident,
    ): ?AgentHandoff {
        $task = null;
        $sourceRun = null;

        try {
            $incident = $incident->fresh(['project', 'task']) ?? $incident;
            $task = $incident->task;

            if (
                RecoveryIncidentStatus::from(
                    (string) $incident->getRawOriginal('status'),
                ) !== RecoveryIncidentStatus::Recovered
                || $incident->recoverable !== true
                || $incident->resolved_at === null
                || $task === null
                || (int) $task->project_id !== (int) $incident->project_id
            ) {
                return null;
            }

            $taskStatus = TaskStatus::from(
                (string) $task->getRawOriginal('status'),
            );

            if (
                ! $taskStatus->isCoderClaimable()
                || $incident->resulting_task_transition !== $taskStatus->value
            ) {
                return null;
            }

            $sourceRun = $incident->recoveryRuns()
                ->where('project_id', $incident->project_id)
                ->where('task_id', $task->id)
                ->where('role', AgentRole::RecoveryEngineer->value)
                ->where('status', AgentRunStatus::Completed->value)
                ->whereNotNull('finished_at')
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();

            if ($sourceRun === null) {
                return null;
            }

            $summary = filled($incident->root_cause)
                ? (string) $incident->root_cause
                : "AIOS accepted a bounded Recovery Engineer outcome for task {$task->key}.";

            $recommendedFocus = filled($incident->fix_summary)
                ? (string) $incident->fix_summary
                : "Resume task {$task->key} through the normal Coder workflow using the accepted recovery diagnosis as bounded evidence.";

            return $this->handoffs->handle(
                $sourceRun,
                AgentRole::Coder,
                AgentHandoffType::RecoveryAdvice,
                AgentHandoffSchemaValidator::SchemaVersion,
                [
                    'summary' => $this->boundedText($summary, 4000),
                    'root_cause_category' => $this->boundedNullableText(
                        is_string($incident->root_cause_category)
                            ? $incident->root_cause_category
                            : null,
                        120,
                    ),
                    'recommended_focus' => $this->boundedText(
                        $recommendedFocus,
                        4000,
                    ),
                    'changed_files' => $this->boundedStringList(
                        $incident->getAttribute('changed_files'),
                        100,
                        512,
                    ),
                    'escalation_reason' => $this->boundedNullableText(
                        is_string($incident->escalation_reason)
                            ? $incident->escalation_reason
                            : null,
                        4000,
                    ),
                ],
            );
        } catch (Throwable $throwable) {
            if ($task === null || $sourceRun === null) {
                report($throwable);

                return null;
            }

            $this->recordFailure(
                $throwable,
                $sourceRun,
                $task,
                AgentRole::Coder,
                AgentHandoffType::RecoveryAdvice,
            );

            return null;
        }
    }

    /**
     * Build the smallest valid implementation-handoff payload from durable AIOS evidence.
     *
     * Optional arrays are included only when they carry evidence. This keeps the canonical
     * payload stable without persisting empty decorative fields.
     *
     * @param  list<string>  $changedFiles
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function implementationPayload(
        string $summary,
        array $changedFiles,
        array $validation,
    ): array {
        $payload = [
            'summary' => $this->boundedText($summary, 4000),
            'changed_files' => $changedFiles,
        ];

        $testFiles = $this->testFiles($changedFiles);

        if ($testFiles !== []) {
            $payload['tests_added_or_updated'] = $testFiles;
        }

        $verificationAttempts = $this->verificationAttempts($validation);

        if ($verificationAttempts !== []) {
            $payload['verification_attempts'] = $verificationAttempts;
        }

        return $payload;
    }

    /**
     * Accept either the legacy task-only commit gate or the P10 durable candidate plus serialized integration gate.
     *
     * Successful P10 integration remains stricter than provider completion. AIOS must have
     * persisted a verified candidate, a successful canonical integration result, and one of
     * the allowlisted successful integration statuses.
     *
     * @param  array<string, mixed>  $validation
     */
    private function implementationValidationSupportsReview(
        array $validation,
    ): bool {
        if (
            ($validation['passed'] ?? false) !== true
            || ! is_array($validation['checks'] ?? null)
        ) {
            return false;
        }

        $checks = $validation['checks'];

        if (($checks['task_commit'] ?? false) === true) {
            return true;
        }

        if (
            ($checks['git_candidate'] ?? false) !== true
            || ($checks['git_integration'] ?? false) !== true
            || ! is_array($validation['git_integration'] ?? null)
        ) {
            return false;
        }

        $integration = $validation['git_integration'];

        return ($integration['passed'] ?? false) === true
            && is_string($integration['status'] ?? null)
            && in_array(
                $integration['status'],
                [
                    'integrated',
                    'already_integrated',
                    'already_satisfied',
                ],
                true,
            );
    }

    /**
     * Verify one completed source run belongs to the exact task attempt and workflow role.
     */
    private function sourceRunMatches(
        AgentRun $sourceRun,
        Task $task,
        TaskAttempt $attempt,
        AgentRole $role,
    ): bool {
        return (int) $sourceRun->project_id === (int) $task->project_id
            && (int) $sourceRun->task_id === (int) $task->id
            && (int) $sourceRun->attempt_number === (int) $attempt->number
            && $sourceRun->getRawOriginal('role') === $role->value
            && $sourceRun->getRawOriginal('status')
                === AgentRunStatus::Completed->value
            && $sourceRun->finished_at !== null;
    }

    /**
     * Normalize mixed persisted list evidence into a bounded list of non-empty strings.
     *
     * @return list<string>
     */
    private function boundedStringList(
        mixed $values,
        int $itemLimit,
        int $stringLimit,
    ): array {
        if (! is_array($values)) {
            return [];
        }

        $bounded = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $bounded[] = $this->boundedText(
                $value,
                $stringLimit,
            );

            if (count($bounded) >= $itemLimit) {
                break;
            }
        }

        return $bounded;
    }

    /**
     * Extract changed files that are recognizably test files without trusting provider output.
     *
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function testFiles(array $changedFiles): array
    {
        $tests = [];

        foreach ($changedFiles as $path) {
            $normalized = strtolower($path);

            if (
                str_starts_with($normalized, 'tests/')
                || str_starts_with($normalized, 'test/')
                || str_contains($normalized, '/tests/')
                || str_contains($normalized, '/test/')
                || str_contains($normalized, '/__tests__/')
                || str_contains($normalized, '.test.')
                || str_contains($normalized, '.spec.')
                || str_ends_with($normalized, 'test.php')
                || str_ends_with($normalized, 'tests.php')
            ) {
                $tests[] = $path;
            }

            if (count($tests) >= 50) {
                break;
            }
        }

        return $tests;
    }

    /**
     * Extract successful configured verification identifiers from persisted AIOS validation evidence.
     *
     * @param  array<string, mixed>  $validation
     * @return list<string>
     */
    private function verificationAttempts(array $validation): array
    {
        $commands = $validation['evidence']['task_verification']['commands'] ?? null;

        if (! is_array($commands)) {
            return [];
        }

        $attempts = [];

        foreach ($commands as $command) {
            if (! is_array($command) || ($command['passed'] ?? false) !== true) {
                continue;
            }

            $identifier = $command['verification_identifier'] ?? null;

            if (is_string($identifier) && $identifier !== '') {
                $attempts[] = $this->boundedText($identifier, 1000);
            }

            if (count($attempts) >= 50) {
                break;
            }
        }

        return array_values(array_unique($attempts));
    }

    /**
     * Convert one persisted ReviewFinding to the existing schema-version-one payload shape.
     *
     * @return array<string, string|null>
     */
    private function reviewFindingPayload(ReviewFinding $finding): array
    {
        return [
            'severity' => $this->boundedText((string) $finding->severity, 32),
            'location' => $this->boundedNullableText(
                is_string($finding->location) ? $finding->location : null,
                1000,
            ),
            'current_implementation' => $this->boundedText(
                (string) $finding->current_implementation,
                4000,
            ),
            'expected_implementation' => $this->boundedText(
                (string) $finding->expected_implementation,
                4000,
            ),
            'why_incorrect' => $this->boundedText(
                (string) $finding->why_incorrect,
                4000,
            ),
            'required_fix' => $this->boundedText(
                (string) $finding->required_fix,
                4000,
            ),
            'verification_requirement' => $this->boundedText(
                (string) $finding->verification_requirement,
                4000,
            ),
            'implementation_fix_context' => $this->boundedText(
                (string) $finding->implementation_fix_context,
                4000,
            ),
        ];
    }

    /**
     * Bound one required text value to the existing Agent handoff schema limit.
     */
    private function boundedText(string $value, int $limit): string
    {
        return Str::limit($value, $limit, '');
    }

    /**
     * Bound one optional text value to the existing Agent handoff schema limit.
     */
    private function boundedNullableText(
        ?string $value,
        int $limit,
    ): ?string {
        return $value === null
            ? null
            : $this->boundedText($value, $limit);
    }

    /**
     * Record handoff persistence failure without allowing collaboration evidence to alter workflow truth.
     */
    private function recordFailure(
        Throwable $throwable,
        AgentRun $sourceRun,
        Task $task,
        AgentRole $toRole,
        AgentHandoffType $type,
    ): void {
        report($throwable);

        try {
            $task->loadMissing('project');

            $this->audit->record(
                'agent_handoff.boundary_recording_failed',
                [
                    'from_agent_run_id' => $sourceRun->id,
                    'handoff_type' => $type->value,
                    'to_role' => $toRole->value,
                    'exception_class' => $throwable::class,
                ],
                $task->project,
                $task,
            );
        } catch (Throwable $auditFailure) {
            report($auditFailure);
        }
    }
}
