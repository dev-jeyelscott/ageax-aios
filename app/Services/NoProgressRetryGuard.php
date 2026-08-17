<?php

namespace App\Services;

use App\AgentRole;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Task;
use App\Models\TaskAttempt;
use Illuminate\Support\Str;

class NoProgressRetryGuard
{
    private const int SummaryLimit = 2000;

    public function __construct(private AgentRunRecorder $runs, private ProjectGitState $git) {}

    /**
     * @return array{
     *     eligible: bool,
     *     failure_fingerprint: ?string,
     *     consecutive_identical_failures: int,
     *     consecutive_repeat_count: int,
     *     threshold: int,
     *     detected: bool,
     *     repository_fingerprint: ?string
     * }
     */
    public function coderFailure(Task $task, TaskAttempt $attempt): array
    {
        $task->loadMissing('project');
        $baseSha = filled($attempt->base_sha) ? (string) $attempt->base_sha : null;
        $repositoryFingerprint = $baseSha === null
            ? null
            : $this->git->workingTreeFingerprintFromBase($task->project->path, $baseSha);

        if ($repositoryFingerprint === null) {
            return $this->unavailable($repositoryFingerprint);
        }

        $validation = $this->decodedObject($attempt->getRawOriginal('validation_results'));
        $runEvidence = $this->runEvidence($task, AgentRole::Coder, $attempt->number, false);
        $fingerprint = $this->fingerprint([
            'operation' => 'coder',
            'base_sha' => $attempt->base_sha,
            'head_sha' => $attempt->head_sha,
            'commit_sha' => $attempt->commit_sha,
            'changed_files' => $this->normalizeFiles($attempt->changed_files),
            'repository_fingerprint' => $repositoryFingerprint,
            'failed_checks' => $this->failedChecks($validation),
            'failed_validation_evidence' => $this->failedValidationEvidence($validation),
            'execution' => $runEvidence,
        ]);
        $previous = $this->previousCoderAttempt($task, $attempt);
        $previousValidation = $previous === null
            ? []
            : $this->decodedObject($previous->getRawOriginal('validation_results'));
        $previousNoProgress = $previousValidation['no_progress'] ?? null;
        $repeatCount = is_array($previousNoProgress)
            && ($previousNoProgress['failure_fingerprint'] ?? null) === $fingerprint
            ? max(0, (int) ($previousNoProgress['consecutive_repeat_count'] ?? 0)) + 1
            : 0;

        return $this->result($fingerprint, $repeatCount, $repositoryFingerprint);
    }

    /**
     * @param  array<string, mixed>  $failure
     * @return array{
     *     eligible: bool,
     *     failure_fingerprint: ?string,
     *     consecutive_identical_failures: int,
     *     consecutive_repeat_count: int,
     *     threshold: int,
     *     detected: bool,
     *     repository_fingerprint: ?string
     * }
     */
    public function reviewerFailure(Task $task, ?TaskAttempt $attempt, array $failure): array
    {
        if ($attempt === null) {
            return $this->unavailable(null);
        }

        $task->loadMissing('project');
        $baseSha = filled($attempt->base_sha) ? (string) $attempt->base_sha : null;
        $repositoryFingerprint = $baseSha === null
            ? null
            : $this->git->workingTreeFingerprintFromBase($task->project->path, $baseSha);

        if ($repositoryFingerprint === null) {
            return $this->unavailable(null);
        }

        $reason = is_string($failure['reason'] ?? null) ? $failure['reason'] : null;
        $runEvidence = $reason === 'agent_misconfigured'
            ? [
                'exit_code' => is_int($failure['exit_code'] ?? null) ? $failure['exit_code'] : null,
                'failure_summary' => $this->normalizedSummary(is_string($failure['error'] ?? null) ? $failure['error'] : null),
            ]
            : $this->runEvidence($task, AgentRole::Reviewer, $attempt->number, true);
        $fingerprint = $this->fingerprint([
            'operation' => 'reviewer',
            'attempt_number' => $attempt->number,
            'base_sha' => $attempt->base_sha,
            'head_sha' => $attempt->head_sha,
            'commit_sha' => $attempt->commit_sha,
            'changed_files' => $this->normalizeFiles($attempt->changed_files),
            'repository_fingerprint' => $repositoryFingerprint,
            'reason' => $reason,
            'exit_code' => is_int($failure['exit_code'] ?? null) ? $failure['exit_code'] : null,
            'error' => $this->normalizedSummary(is_string($failure['error'] ?? null) ? $failure['error'] : null),
            'execution' => $runEvidence,
        ]);
        $previousFailure = $this->previousReviewerFailure($task, $attempt);
        $previousPayload = $previousFailure === null
            ? []
            : $this->decodedObject($previousFailure->getRawOriginal('payload'));
        $previousNoProgress = $previousPayload['no_progress'] ?? null;
        $repeatCount = is_array($previousNoProgress)
            && ($previousNoProgress['failure_fingerprint'] ?? null) === $fingerprint
            ? max(0, (int) ($previousNoProgress['consecutive_repeat_count'] ?? 0)) + 1
            : 0;

        return $this->result($fingerprint, $repeatCount, $repositoryFingerprint);
    }

    private function previousCoderAttempt(Task $task, TaskAttempt $attempt): ?TaskAttempt
    {
        $previous = $task->attempts()
            ->where('number', '<', $attempt->number)
            ->orderByDesc('number')
            ->first();

        if ($previous === null || $previous->status !== 'failed') {
            return null;
        }

        $finishedAt = $previous->getRawOriginal('finished_at');

        if ($finishedAt !== null && $task->auditEvents()
            ->where('event_type', 'task.requeued')
            ->where('occurred_at', '>=', $finishedAt)
            ->exists()) {
            return null;
        }

        return $previous;
    }

    private function previousReviewerFailure(Task $task, TaskAttempt $attempt): ?AuditEvent
    {
        $lastRequeueId = $task->auditEvents()
            ->where('event_type', 'task.requeued')
            ->max('id');
        $events = $task->auditEvents()
            ->where('event_type', 'review.failed')
            ->when($lastRequeueId !== null, fn ($query) => $query->where('id', '>', $lastRequeueId))
            ->orderByDesc('id')
            ->get();

        return $events->first(function (AuditEvent $event) use ($attempt): bool {
            $payload = $this->decodedObject($event->getRawOriginal('payload'));

            return ($payload['attempt_number'] ?? null) === $attempt->number;
        });
    }

    /** @return array{exit_code: ?int, failure_summary: ?string} */
    private function runEvidence(Task $task, AgentRole $role, int $attemptNumber, bool $includeSuccessfulTranscript): array
    {
        $run = AgentRun::query()
            ->whereBelongsTo($task)
            ->where('role', $role->value)
            ->where('attempt_number', $attemptNumber)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            return ['exit_code' => null, 'failure_summary' => null];
        }

        $summary = $this->runs->failureReason($run);

        if ($summary === null && ($includeSuccessfulTranscript || $run->exit_code !== 0)) {
            $summary = $this->runs->transcript($run);
        }

        return [
            'exit_code' => $run->exit_code,
            'failure_summary' => $this->normalizedSummary($summary),
        ];
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return list<string>
     */
    private function failedChecks(array $validation): array
    {
        $checks = [];
        $source = is_array($validation['checks'] ?? null) ? $validation['checks'] : [];

        foreach ($source as $name => $passed) {
            if (is_string($name) && $passed === false) {
                $checks[] = $name;
            }
        }

        sort($checks, SORT_STRING);

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return list<array<string, mixed>>
     */
    private function failedValidationEvidence(array $validation): array
    {
        $items = [];
        $evidence = is_array($validation['evidence'] ?? null) ? $validation['evidence'] : [];
        $this->collectFailedEvidence($evidence, 'evidence', $items);
        usort($items, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $items;
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<int, array<string, mixed>>  $items
     */
    private function collectFailedEvidence(array $value, string $path, array &$items): void
    {
        if (($value['passed'] ?? null) === false) {
            $items[] = [
                'path' => $path,
                'name' => is_string($value['name'] ?? null) ? $value['name'] : null,
                'verification_identifier' => is_string($value['verification_identifier'] ?? null) ? $value['verification_identifier'] : null,
                'exit_code' => is_int($value['exit_code'] ?? null) ? $value['exit_code'] : null,
                'files' => $this->normalizeFiles($value['files'] ?? []),
                'summary' => $this->normalizedSummary(is_string($value['summary'] ?? null) ? $value['summary'] : null),
            ];
        }

        foreach ($value as $key => $nested) {
            if (! is_array($nested)) {
                continue;
            }

            $this->collectFailedEvidence($nested, $path.'.'.(string) $key, $items);
        }
    }

    /** @return list<string> */
    private function normalizeFiles(mixed $files): array
    {
        if (! is_array($files)) {
            return [];
        }

        $normalized = array_values(array_unique(array_filter($files, fn (mixed $file): bool => is_string($file) && $file !== '')));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function decodedObject(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function normalizedSummary(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $redacted = $this->runs->redact($summary);
        $redacted = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $redacted) ?? $redacted;
        $redacted = Str::squish($redacted);

        if ($redacted === '') {
            return null;
        }

        return Str::length($redacted) > self::SummaryLimit
            ? '…'.Str::substr($redacted, -self::SummaryLimit)
            : $redacted;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        $encoded = json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return hash('sha256', $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }

    /**
     * @return array{
     *     eligible: true,
     *     failure_fingerprint: string,
     *     consecutive_identical_failures: int,
     *     consecutive_repeat_count: int,
     *     threshold: int,
     *     detected: bool,
     *     repository_fingerprint: ?string
     * }
     */
    private function result(string $fingerprint, int $repeatCount, ?string $repositoryFingerprint): array
    {
        $threshold = $this->threshold();

        return [
            'eligible' => true,
            'failure_fingerprint' => $fingerprint,
            'consecutive_identical_failures' => $repeatCount + 1,
            'consecutive_repeat_count' => $repeatCount,
            'threshold' => $threshold,
            'detected' => $repeatCount >= $threshold,
            'repository_fingerprint' => $repositoryFingerprint,
        ];
    }

    /**
     * @return array{
     *     eligible: false,
     *     failure_fingerprint: null,
     *     consecutive_identical_failures: int,
     *     consecutive_repeat_count: int,
     *     threshold: int,
     *     detected: false,
     *     repository_fingerprint: ?string
     * }
     */
    private function unavailable(?string $repositoryFingerprint): array
    {
        return [
            'eligible' => false,
            'failure_fingerprint' => null,
            'consecutive_identical_failures' => 0,
            'consecutive_repeat_count' => 0,
            'threshold' => $this->threshold(),
            'detected' => false,
            'repository_fingerprint' => $repositoryFingerprint,
        ];
    }

    private function threshold(): int
    {
        return max(1, (int) config('aios.no_progress_repeat_threshold'));
    }
}
