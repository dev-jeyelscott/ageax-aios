<?php

namespace App\Services;

use App\Models\RecoveryIncident;
use App\RuntimeRecoverabilityClassification;
use App\RuntimeRecoveryIncidentFamily;
use Illuminate\Support\Str;

class RuntimeRecoverabilityPolicy
{
    private const string StaleWorkerSource = 'worker:expired_lease';

    private const string StaleWorkerRepair = 'stale_worker_recovery';

    /** @var list<string> */
    private const array OperatorOnlyExceptionMarkers = [
        'authentication',
        'authorization',
        'accessdenied',
        'permissiondenied',
        'credential',
        'security',
        'privacy',
        'tokenmismatch',
        'encryption',
        'decryption',
        'crypt',
        'database',
        'pdoexception',
        'queryexception',
        'databaseprotection',
        'projectdatabaseisolation',
        'unsafeprojectpath',
    ];

    /** @var list<string> */
    private const array OperatorOnlySourceMarkers = [
        'auth',
        'security',
        'privacy',
        'login',
        'logout',
        'password',
        'passkey',
        'two-factor',
        'verification',
        'csrf',
        'database',
        'db:',
        'migrate',
        'schema',
        'backup',
        'restore',
    ];

    /** @var list<string> */
    private const array OperatorOnlyMessageMarkers = [
        'sqlstate',
        'database',
        'authentication',
        'authorization',
        'unauthenticated',
        'unauthorized',
        'forbidden',
        'credential',
        'security',
        'privacy',
        'personal data',
        'secret',
        'permission denied',
        'csrf',
        'encryption',
        'decryption',
        'private key',
    ];

    /**
     * Classify one runtime incident using only sanitized durable AIOS evidence and allowlisted policy.
     *
     * @return array{
     *     category: string,
     *     summary: string,
     *     recoverable: bool,
     *     fix_applied: false,
     *     changed_files: array<int, string>,
     *     fix_summary: null,
     *     escalation_reason: ?string,
     *     deterministic_repair: ?string
     * }
     */
    public function classify(RecoveryIncident $incident): array
    {
        $family = RuntimeRecoveryIncidentFamily::tryFrom((string) $incident->failure_type);

        if ($family === null || blank($incident->fingerprint) || blank($incident->source)) {
            return $this->classification(
                RuntimeRecoverabilityClassification::NonActionable,
                'The runtime incident does not contain the stable identity required for deterministic retry or repair policy.',
                false,
                null,
                null,
            );
        }

        if ($incident->project_id === null) {
            return $this->classification(
                RuntimeRecoverabilityClassification::OperatorOnly,
                'The runtime incident is not provably scoped to a managed project, so automatic repair cannot establish a safe execution boundary.',
                false,
                'Unscoped runtime incidents require operator review before any recovery execution is allowed.',
                null,
            );
        }

        if ($this->requiresOperatorEscalation($incident, $family)) {
            return $this->classification(
                RuntimeRecoverabilityClassification::OperatorOnly,
                'The runtime incident intersects a protected security, authentication, authorization, database, credential, or high-blast-radius boundary.',
                false,
                'Protected runtime incident classes fail closed to operator review and are never promoted to automatic repair by an Agent.',
                null,
            );
        }

        if ($this->isKnownStaleWorkerFailure($incident, $family)) {
            return $this->classification(
                RuntimeRecoverabilityClassification::KnownDeterministicRepair,
                'The runtime incident identifies an expired worker lease that is already covered by AIOS deterministic stale-worker recovery.',
                true,
                null,
                self::StaleWorkerRepair,
            );
        }

        if ($family === RuntimeRecoveryIncidentFamily::SystemWorkerFailure) {
            return $this->classification(
                RuntimeRecoverabilityClassification::OperatorOnly,
                'The system worker failure is not one of the explicitly allowlisted deterministic recovery cases.',
                false,
                'Unknown worker failures affect AIOS orchestration state and require operator review.',
                null,
            );
        }

        return $this->classification(
            RuntimeRecoverabilityClassification::CandidateAiRepair,
            'The runtime incident is bounded and project-scoped, but no deterministic repair is proven safe.',
            true,
            null,
            null,
        );
    }

    /**
     * Determine whether protected runtime evidence must default to operator-only escalation.
     */
    private function requiresOperatorEscalation(RecoveryIncident $incident, RuntimeRecoveryIncidentFamily $family): bool
    {
        $exceptionClass = Str::lower((string) $incident->exception_class);
        $source = Str::lower((string) $incident->source);
        $message = Str::lower($this->evidenceMessage($incident));

        if (Str::contains($exceptionClass, self::OperatorOnlyExceptionMarkers)) {
            return true;
        }

        if (Str::contains($source, self::OperatorOnlySourceMarkers)) {
            return true;
        }

        if (Str::contains($message, self::OperatorOnlyMessageMarkers)) {
            return true;
        }

        return $family === RuntimeRecoveryIncidentFamily::ScheduledCommandFailure
            && Str::contains($source, ['queue:restart', 'config:cache', 'route:cache']);
    }

    /**
     * Recognize only the exact system-worker failure shape already covered by StaleWorkerRecovery.
     */
    private function isKnownStaleWorkerFailure(RecoveryIncident $incident, RuntimeRecoveryIncidentFamily $family): bool
    {
        return $family === RuntimeRecoveryIncidentFamily::SystemWorkerFailure
            && $incident->agent_worker_id !== null
            && hash_equals(self::StaleWorkerSource, (string) $incident->source);
    }

    /**
     * Return only the bounded sanitized top-level message supplied by runtime ingestion.
     */
    private function evidenceMessage(RecoveryIncident $incident): string
    {
        $rawEvidence = $incident->getRawOriginal('evidence');

        if (! is_string($rawEvidence) || $rawEvidence === '') {
            return '';
        }

        $evidence = json_decode($rawEvidence, true);

        if (! is_array($evidence)) {
            return '';
        }

        $message = $evidence['message'] ?? null;

        return is_string($message) ? $message : '';
    }

    /**
     * Build the uniform recovery classification shape consumed by WorkflowRecoveryEngine.
     *
     * @return array{
     *     category: string,
     *     summary: string,
     *     recoverable: bool,
     *     fix_applied: false,
     *     changed_files: array<int, string>,
     *     fix_summary: null,
     *     escalation_reason: ?string,
     *     deterministic_repair: ?string
     * }
     */
    private function classification(
        RuntimeRecoverabilityClassification $classification,
        string $summary,
        bool $recoverable,
        ?string $escalationReason,
        ?string $deterministicRepair,
    ): array {
        return [
            'category' => $classification->value,
            'summary' => $summary,
            'recoverable' => $recoverable,
            'fix_applied' => false,
            'changed_files' => [],
            'fix_summary' => null,
            'escalation_reason' => $escalationReason,
            'deterministic_repair' => $deterministicRepair,
        ];
    }
}
