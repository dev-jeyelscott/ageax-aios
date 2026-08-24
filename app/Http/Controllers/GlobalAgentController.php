<?php

namespace App\Http\Controllers;

use App\AgentRole;
use App\Http\Requests\UpdateGlobalAgentRequest;
use App\Jobs\RunWorkflowRecoveryEngineerNow;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\RecoveryIncident;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoverabilityClassification;
use App\RuntimeRecoveryIncidentFamily;
use App\Services\AgentHarnessResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\SensitiveDataSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GlobalAgentController extends Controller
{
    private const int RecoveryUiTextLimit = 4000;

    /** @var list<string> */
    private const array RecoveryBlockingEvents = [
        'recovery.blocked_agent_misconfigured',
        'recovery.blocked_worktree_isolation_failed',
        'recovery.blocked_database_protection_failed',
        'recovery.runtime_ai_repair_preparation_failed',
    ];

    /** Inject controller collaborators for audit logging and defense-in-depth sanitization. */
    public function __construct(
        private AuditLogger $audit,
        private SensitiveDataSanitizer $sanitizer,
    ) {}

    /** Render the global Agent command center and durable system recovery summary. */
    public function index(): Response
    {
        $openStatuses = array_map(
            fn (RecoveryIncidentStatus $status): string => $status->value,
            RecoveryIncidentStatus::open(),
        );
        $activeStatuses = [
            RecoveryIncidentStatus::Diagnosing->value,
            RecoveryIncidentStatus::Repairing->value,
            RecoveryIncidentStatus::Validating->value,
        ];
        $agents = Agent::query()->whereNull('project_id')->orderBy('name')->get();
        $agentPayload = [];

        foreach ($agents as $agent) {
            $recentRuns = AgentRun::query()
                ->where('agent_id', $agent->id)
                ->latest('started_at')
                ->limit(8)
                ->get();
            $latestRun = $recentRuns->first();
            $recentActivity = [];

            foreach ($recentRuns->reverse()->values() as $run) {
                $recentActivity[] = [
                    'id' => $run->id,
                    'status' => (string) $run->getRawOriginal('status'),
                    'started_at' => $this->timestampToIso8601($run->getRawOriginal('started_at')),
                    'finished_at' => $this->nullableTimestampToIso8601($run->getRawOriginal('finished_at')),
                    'duration_seconds' => $this->runDurationSeconds($run),
                ];
            }

            $agentPayload[] = [
                'id' => $agent->id,
                'name' => $agent->name,
                'role' => (string) $agent->getRawOriginal('role'),
                'harness' => (string) $agent->getRawOriginal('harness'),
                'model' => $agent->model,
                'reasoning_setting' => $agent->reasoning_setting,
                'enabled' => $agent->enabled,
                'configuration_version' => $agent->configuration_version,
                'open_incident_count' => $this->isRecoveryEngineer($agent)
                    ? RecoveryIncident::query()->whereIn('status', $openStatuses)->count()
                    : 0,
                'runtime_status' => ! $agent->enabled
                    ? 'disabled'
                    : ($this->invokeInProgress($agent) ? 'working' : 'idle'),
                'latest_run' => $latestRun === null ? null : [
                    'id' => $latestRun->id,
                    'status' => (string) $latestRun->getRawOriginal('status'),
                    'started_at' => $this->timestampToIso8601($latestRun->getRawOriginal('started_at')),
                    'finished_at' => $this->nullableTimestampToIso8601($latestRun->getRawOriginal('finished_at')),
                ],
                'recent_activity' => $recentActivity,
            ];
        }

        $activeIncidentPayload = RecoveryIncident::query()
            ->whereIn('status', $openStatuses)
            ->with(['project:id,name', 'task:id,key,title,project_id'])
            ->latest('detected_at')
            ->limit(5)
            ->get()
            ->map(fn (RecoveryIncident $incident): array => [
                'id' => $incident->id,
                'status' => (string) $incident->getRawOriginal('status'),
                'failure_type' => $incident->failure_type,
                'root_cause_category' => $incident->root_cause_category,
                'detected_at' => $this->timestampToIso8601($incident->getRawOriginal('detected_at')),
                'project' => $incident->project === null ? null : [
                    'id' => $incident->project->id,
                    'name' => $incident->project->name,
                ],
                'task' => $incident->task === null ? null : [
                    'key' => $incident->task->key,
                    'title' => $incident->task->title,
                    'project_id' => $incident->task->project_id,
                ],
            ])
            ->values()
            ->all();

        $recentEventPayload = AuditEvent::query()
            ->with('project:id,name')
            ->latest('occurred_at')
            ->limit(6)
            ->get(['id', 'project_id', 'event_type', 'occurred_at'])
            ->map(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'occurred_at' => $this->timestampToIso8601($event->getRawOriginal('occurred_at')),
                'project' => $event->project === null ? null : [
                    'id' => $event->project->id,
                    'name' => $event->project->name,
                ],
            ])
            ->values()
            ->all();

        return Inertia::render('agents/index', [
            'agents' => $agentPayload,
            'system' => [
                'total_agents' => $agents->count(),
                'enabled_agents' => $agents->filter(fn (Agent $agent): bool => $agent->enabled)->count(),
                'open_incidents' => RecoveryIncident::query()->whereIn('status', $openStatuses)->count(),
                'active_recoveries' => RecoveryIncident::query()->whereIn('status', $activeStatuses)->count(),
            ],
            'recent_events' => $recentEventPayload,
            'active_incidents' => $activeIncidentPayload,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** Render one global Agent and the Recovery Engineer incident evidence when applicable. */
    public function show(Agent $agent, AgentHarnessResolver $harnesses): Response
    {
        abort_unless($agent->project_id === null, 404);

        $agent->setAttribute('invoke_in_progress', $this->invokeInProgress($agent));
        $isRecoveryEngineer = $this->isRecoveryEngineer($agent);
        $incidents = RecoveryIncident::query()
            ->when(! $isRecoveryEngineer, fn ($query) => $query->whereKey(-1))
            ->with([
                'project:id,name',
                'task:id,key,title,project_id',
                'recoveryRuns' => function (Relation $relation): void {
                    $relation->getQuery()
                        ->where('role', AgentRole::RecoveryEngineer->value)
                        ->orderByDesc('started_at')
                        ->orderByDesc('id');
                },
            ])
            ->latest('detected_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
        $incidentIds = $incidents->getCollection()
            ->map(fn (RecoveryIncident $incident): int => $incident->id)
            ->all();
        $events = $this->recoveryEvents($incidentIds);

        $incidents->through(fn (RecoveryIncident $incident): array => $this->incidentPayload(
            $incident,
            $agent,
            $events[$incident->id] ?? [],
        ));

        return Inertia::render('agents/show', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'role' => (string) $agent->getRawOriginal('role'),
                'harness' => (string) $agent->getRawOriginal('harness'),
                'model' => $agent->model,
                'reasoning_setting' => $agent->reasoning_setting,
                'default_context' => $agent->default_context,
                'enabled' => $agent->enabled,
                'configuration_version' => $agent->configuration_version,
                'invoke_in_progress' => (bool) $agent->getAttribute('invoke_in_progress'),
            ],
            'incidents' => $incidents,
            'harness_capabilities' => fn (): array => $harnesses->capabilities(),
        ]);
    }

    /** Persist an authorized global Agent configuration update and audit evidence. */
    public function update(UpdateGlobalAgentRequest $request, Agent $agent): RedirectResponse
    {
        abort_unless($agent->project_id === null, 404);

        DB::transaction(function () use ($request, $agent): void {
            $lockedAgent = Agent::query()
                ->whereKey($agent->getKey())
                ->whereNull('project_id')
                ->lockForUpdate()
                ->firstOrFail();
            $previousVersion = $lockedAgent->configuration_version;
            $wasEnabled = $lockedAgent->enabled;

            $lockedAgent->update($request->validated());
            $lockedAgent->refresh();

            if ($lockedAgent->configuration_version === $previousVersion) {
                return;
            }

            $payload = [
                'agent_id' => $lockedAgent->id,
                'previous_configuration_version' => $previousVersion,
                'configuration_version' => $lockedAgent->configuration_version,
                'role' => (string) $lockedAgent->getRawOriginal('role'),
                'harness' => (string) $lockedAgent->getRawOriginal('harness'),
            ];
            $this->audit->record('agent.updated', $payload);

            if ($wasEnabled !== $lockedAgent->enabled) {
                $this->audit->record(
                    $lockedAgent->enabled ? 'agent.enabled' : 'agent.disabled',
                    $payload,
                );
            }
        }, attempts: 3);

        return to_route('agents.show', $agent);
    }

    /** Queue the existing manual Recovery Engineer job without creating a second recovery path. */
    public function invoke(Agent $agent): RedirectResponse
    {
        abort_unless($agent->project_id === null, 404);
        abort_unless($this->isRecoveryEngineer($agent), 404);
        abort_unless($agent->enabled, 422, 'A disabled Agent cannot be invoked.');
        abort_if(
            $this->invokeInProgress($agent),
            409,
            'The Workflow Recovery Engineer is already working.',
        );

        RunWorkflowRecoveryEngineerNow::dispatch($agent->id);
        $this->audit->record('agent.invoke_requested', [
            'agent_id' => $agent->id,
            'role' => (string) $agent->getRawOriginal('role'),
        ]);

        return to_route('agents.show', $agent);
    }

    /** Render one global AgentRun while excluding raw provider and filesystem output. */
    public function showRun(
        Agent $agent,
        AgentRun $run,
        AgentRunRecorder $runs,
    ): Response {
        abort_unless($agent->project_id === null, 404);
        abort_unless($run->agent_id === $agent->id, 404);

        $run->loadMissing('task:id,key,title', 'project:id,name');
        $run->setAttribute('agent_messages', $runs->agentMessages($run));
        $run->makeHidden(['log_path', 'live_output', 'result']);

        return Inertia::render('agents/run-show', [
            'agent' => $agent->only(['id', 'name', 'role']),
            'agent_run' => $run,
        ]);
    }

    /**
     * Shape one incident using only bounded, sanitized, operator-safe durable evidence.
     *
     * @param  array<string, AuditEvent>  $events
     * @return array<string, mixed>
     */
    private function incidentPayload(
        RecoveryIncident $incident,
        Agent $agent,
        array $events,
    ): array {
        $isRuntime = RuntimeRecoveryIncidentFamily::tryFrom((string) $incident->failure_type) !== null;
        $breaker = $events['recovery.runtime_circuit_breaker_opened'] ?? null;
        $blockingEvent = null;

        foreach (self::RecoveryBlockingEvents as $eventType) {
            if (
                isset($events[$eventType])
                && ($blockingEvent === null || $events[$eventType]->id > $blockingEvent->id)
            ) {
                $blockingEvent = $events[$eventType];
            }
        }

        $attemptFailure = $events['recovery.runtime_attempt_failed'] ?? null;
        $outcome = $this->operatorOutcome($incident, $breaker, $blockingEvent);
        $runtimeEvidence = null;

        if ($isRuntime) {
            $source = is_array($incident->evidence) ? $incident->evidence : [];
            $stack = [];

            foreach (array_slice(is_array($source['stack'] ?? null) ? $source['stack'] : [], 0, 8) as $frame) {
                if (! is_array($frame) || ! is_string($frame['file'] ?? null)) {
                    continue;
                }

                $safeFrame = ['file' => $this->safeText($frame['file'], 512)];

                if (is_int($frame['line'] ?? null) && $frame['line'] > 0) {
                    $safeFrame['line'] = $frame['line'];
                }

                foreach (['class', 'function'] as $key) {
                    if (is_string($frame[$key] ?? null)) {
                        $safeFrame[$key] = $this->safeText($frame[$key], 255);
                    }
                }

                $stack[] = $safeFrame;
            }

            $runtimeEvidence = [
                'message' => is_string($source['message'] ?? null)
                    ? $this->safeText($source['message'])
                    : null,
                'stack' => $stack,
            ];
        }

        $recoveryRuns = $incident->recoveryRuns
            ->map(fn (AgentRun $run): array => [
                'id' => $run->id,
                'status' => (string) $run->getRawOriginal('status'),
                'attempt_number' => is_numeric($run->attempt_number) ? (int) $run->attempt_number : null,
                'started_at' => $this->timestampToIso8601($run->getRawOriginal('started_at')),
                'finished_at' => $this->nullableTimestampToIso8601($run->getRawOriginal('finished_at')),
                'exit_code' => is_numeric($run->exit_code) ? (int) $run->exit_code : null,
                'viewable_in_agent_console' => $run->agent_id === $agent->id,
            ])
            ->values()
            ->all();
        $validation = is_array($incident->validation_evidence)
            ? $incident->validation_evidence
            : null;
        $checks = [];

        if (is_array($validation['checks'] ?? null)) {
            foreach ($validation['checks'] as $name => $passed) {
                if (is_string($name) && is_bool($passed)) {
                    $checks[] = ['name' => $this->safeText($name, 255), 'passed' => $passed];
                }
            }

            usort(
                $checks,
                fn (array $left, array $right): int => strcmp($left['name'], $right['name']),
            );
        }

        $validationPayload = $validation === null ? null : [
            'passed' => is_bool($validation['passed'] ?? null)
                ? $validation['passed']
                : ($checks === []
                    ? null
                    : ! collect($checks)->contains(fn (array $check): bool => ! $check['passed'])),
            'checks' => $checks,
        ];
        $changedFiles = collect(is_array($incident->changed_files) ? $incident->changed_files : [])
            ->filter(fn (mixed $file): bool => is_string($file))
            ->map(fn (string $file): string => $this->safeText($file, 512))
            ->values()
            ->all();
        $gitPayload = blank($incident->base_sha)
            && blank($incident->head_sha)
            && blank($incident->commit_sha)
            && $changedFiles === []
                ? null
                : [
                    'base_sha' => $this->safeNullableText($incident->base_sha, 128),
                    'head_sha' => $this->safeNullableText($incident->head_sha, 128),
                    'commit_sha' => $this->safeNullableText($incident->commit_sha, 128),
                    'changed_files' => $changedFiles,
                ];
        $breakerPayload = $breaker?->payload ?? [];
        $circuitBreaker = [
            'state' => ! $isRuntime ? 'not_applicable' : ($breaker === null ? 'closed' : 'opened'),
            'failure_fingerprint' => $this->safeNullableText(
                $breakerPayload['failure_fingerprint'] ?? null,
                128,
            ),
            'consecutive_repeat_count' => is_numeric(
                $breakerPayload['consecutive_repeat_count'] ?? null,
            )
                ? (int) $breakerPayload['consecutive_repeat_count']
                : null,
            'threshold' => is_numeric($breakerPayload['threshold'] ?? null)
                ? (int) $breakerPayload['threshold']
                : null,
            'attempt_count' => is_numeric($breakerPayload['attempt_count'] ?? null)
                ? (int) $breakerPayload['attempt_count']
                : null,
            'occurred_at' => $breaker === null
                ? null
                : $this->timestampToIso8601($breaker->getRawOriginal('occurred_at')),
        ];
        $blockingEvidence = $blockingEvent ?? $attemptFailure;
        $blockingPayload = $blockingEvidence?->payload ?? [];

        return [
            'id' => $incident->id,
            'is_runtime' => $isRuntime,
            'failure_type' => (string) $incident->failure_type,
            'status' => (string) $incident->getRawOriginal('status'),
            'operator_outcome' => $outcome,
            'root_cause_category' => $this->safeNullableText($incident->root_cause_category, 255),
            'root_cause' => $this->safeNullableText($incident->root_cause),
            'recoverable' => $incident->recoverable,
            'fingerprint' => $this->safeNullableText($incident->fingerprint, 128),
            'source' => $this->safeNullableText($incident->source, 512),
            'exception_class' => $this->safeNullableText($incident->exception_class, 512),
            'occurrence_count' => (int) $incident->occurrence_count,
            'first_seen_at' => $this->nullableTimestampToIso8601(
                $incident->getRawOriginal('first_seen_at'),
            ),
            'last_seen_at' => $this->nullableTimestampToIso8601(
                $incident->getRawOriginal('last_seen_at'),
            ),
            'detected_at' => $this->timestampToIso8601(
                $incident->getRawOriginal('detected_at'),
            ),
            'resolved_at' => $this->nullableTimestampToIso8601(
                $incident->getRawOriginal('resolved_at'),
            ),
            'attempt_count' => (int) $incident->attempt_count,
            'fix_summary' => $this->safeNullableText($incident->fix_summary),
            'escalation_reason' => $this->safeNullableText($incident->escalation_reason),
            'resulting_task_transition' => $this->safeNullableText(
                $incident->resulting_task_transition,
                512,
            ),
            'project' => $incident->project === null ? null : [
                'id' => $incident->project->id,
                'name' => $incident->project->name,
            ],
            'task' => $incident->task === null ? null : [
                'key' => $incident->task->key,
                'title' => $incident->task->title,
                'project_id' => $incident->task->project_id,
            ],
            'evidence' => $runtimeEvidence,
            'recovery_runs' => $recoveryRuns,
            'git' => $gitPayload,
            'validation' => $validationPayload,
            'circuit_breaker' => $circuitBreaker,
            'blocking' => [
                'state' => $outcome === 'blocked' ? 'blocked' : 'clear',
                'event_type' => $outcome === 'blocked'
                    ? $blockingEvidence?->event_type
                    : null,
                'reason' => $outcome === 'blocked'
                    ? $this->safeNullableText($blockingPayload['reason'] ?? null)
                        ?? $this->safeNullableText($incident->escalation_reason)
                        ?? 'Automatic recovery stopped because durable evidence requires operator attention.'
                    : null,
                'occurred_at' => $outcome === 'blocked' && $blockingEvidence !== null
                    ? $this->timestampToIso8601(
                        $blockingEvidence->getRawOriginal('occurred_at'),
                    )
                    : null,
            ],
        ];
    }

    /**
     * Load the newest relevant append-only recovery events for incidents on the current page.
     *
     * @param  list<int>  $incidentIds
     * @return array<int, array<string, AuditEvent>>
     */
    private function recoveryEvents(array $incidentIds): array
    {
        if ($incidentIds === []) {
            return [];
        }

        $events = AuditEvent::query()
            ->whereIn('event_type', [
                'recovery.runtime_circuit_breaker_opened',
                'recovery.runtime_attempt_failed',
                ...self::RecoveryBlockingEvents,
            ])
            ->whereIn('payload->recovery_incident_id', $incidentIds)
            ->orderByDesc('id')
            ->get(['id', 'event_type', 'payload', 'occurred_at']);

        /** @var array<int, array<string, AuditEvent>> $grouped */
        $grouped = [];

        foreach ($events as $event) {
            $value = $event->payload['recovery_incident_id'] ?? null;
            $incidentId = is_int($value)
                ? $value
                : (is_string($value) && ctype_digit($value) ? (int) $value : null);

            if ($incidentId === null || isset($grouped[$incidentId][$event->event_type])) {
                continue;
            }

            $grouped[$incidentId][$event->event_type] = $event;
        }

        return $grouped;
    }

    /** Derive the operator-facing outcome from existing durable status and evidence only. */
    private function operatorOutcome(
        RecoveryIncident $incident,
        ?AuditEvent $breaker,
        ?AuditEvent $blockingEvent,
    ): string {
        $status = RecoveryIncidentStatus::from(
            (string) $incident->getRawOriginal('status'),
        );

        if ($status->isOpen()) {
            return 'automatic';
        }

        if ($status === RecoveryIncidentStatus::Recovered) {
            return 'resolved';
        }

        if ($status === RecoveryIncidentStatus::Failed) {
            return 'blocked';
        }

        if ($status === RecoveryIncidentStatus::Escalated) {
            return $breaker === null
                && $blockingEvent === null
                && $incident->root_cause_category
                    === RuntimeRecoverabilityClassification::OperatorOnly->value
                    ? 'escalated'
                    : 'blocked';
        }

        return 'blocked';
    }

    /** Sanitize and bound required text before returning it to Inertia. */
    private function safeText(
        string $value,
        int $limit = self::RecoveryUiTextLimit,
    ): string {
        return Str::substr(
            $this->sanitizer->sanitizeText($value),
            0,
            $limit,
        );
    }

    /** Sanitize and bound optional text before returning it to Inertia. */
    private function safeNullableText(
        mixed $value,
        int $limit = self::RecoveryUiTextLimit,
    ): ?string {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->safeText($value, $limit);
    }

    /** Determine whether the Recovery Engineer currently owns an active incident claim. */
    private function invokeInProgress(Agent $agent): bool
    {
        if (! $this->isRecoveryEngineer($agent)) {
            return false;
        }

        return RecoveryIncident::query()
            ->whereIn('status', [
                RecoveryIncidentStatus::Diagnosing,
                RecoveryIncidentStatus::Repairing,
                RecoveryIncidentStatus::Validating,
            ])
            ->exists();
    }

    /** Determine whether the supplied Agent is the protected global Recovery Engineer. */
    private function isRecoveryEngineer(Agent $agent): bool
    {
        return AgentRole::tryFrom(
            (string) $agent->getRawOriginal('role'),
        ) === AgentRole::RecoveryEngineer;
    }

    /** Calculate a completed AgentRun duration in seconds. */
    private function runDurationSeconds(AgentRun $run): ?int
    {
        $finishedAt = $run->getRawOriginal('finished_at');

        if ($finishedAt === null) {
            return null;
        }

        $startedAt = CarbonImmutable::parse(
            (string) $run->getRawOriginal('started_at'),
        );
        $finishedAt = CarbonImmutable::parse((string) $finishedAt);

        return max(
            0,
            (int) round($startedAt->diffInSeconds($finishedAt)),
        );
    }

    /** Serialize one required persisted timestamp to ISO 8601. */
    private function timestampToIso8601(mixed $value): string
    {
        return CarbonImmutable::parse(
            (string) $value,
        )->toIso8601String();
    }

    /** Serialize one optional persisted timestamp to ISO 8601. */
    private function nullableTimestampToIso8601(mixed $value): ?string
    {
        return $value === null
            ? null
            : $this->timestampToIso8601($value);
    }
}
