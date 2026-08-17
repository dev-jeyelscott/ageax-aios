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
use App\Services\AgentHarnessResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GlobalAgentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

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

        $agents = Agent::query()
            ->whereNull('project_id')
            ->orderBy('name')
            ->get();

        /** @var list<array<string, mixed>> $agentPayload */
        $agentPayload = [];

        foreach ($agents as $agent) {
            $recentRuns = AgentRun::query()
                ->where('agent_id', $agent->id)
                ->latest('started_at')
                ->limit(8)
                ->get();

            $latestRun = $recentRuns->first();

            $openIncidentCount = RecoveryIncident::query()
                ->whereHas(
                    'recoveryRuns',
                    fn (Builder $query) => $query->where('agent_id', $agent->id),
                )
                ->whereIn('status', $openStatuses)
                ->count();

            /** @var list<array<string, mixed>> $recentActivity */
            $recentActivity = [];

            foreach ($recentRuns->reverse()->values() as $run) {
                $recentActivity[] = [
                    'id' => $run->id,
                    'status' => (string) $run->getRawOriginal('status'),
                    'started_at' => $this->timestampToIso8601(
                        $run->getRawOriginal('started_at'),
                    ),
                    'finished_at' => $this->nullableTimestampToIso8601(
                        $run->getRawOriginal('finished_at'),
                    ),
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
                'open_incident_count' => $openIncidentCount,
                'runtime_status' => ! $agent->enabled
                    ? 'disabled'
                    : ($this->invokeInProgress($agent) ? 'working' : 'idle'),
                'latest_run' => $latestRun === null
                    ? null
                    : [
                        'id' => $latestRun->id,
                        'status' => (string) $latestRun->getRawOriginal('status'),
                        'started_at' => $this->timestampToIso8601(
                            $latestRun->getRawOriginal('started_at'),
                        ),
                        'finished_at' => $this->nullableTimestampToIso8601(
                            $latestRun->getRawOriginal('finished_at'),
                        ),
                    ],
                'recent_activity' => $recentActivity,
            ];
        }

        /** @var list<array<string, mixed>> $activeIncidentPayload */
        $activeIncidentPayload = [];

        $activeIncidents = RecoveryIncident::query()
            ->whereIn('status', $openStatuses)
            ->with([
                'project:id,name',
                'task:id,key,title,project_id',
            ])
            ->latest('detected_at')
            ->limit(5)
            ->get();

        foreach ($activeIncidents as $incident) {
            $activeIncidentPayload[] = [
                'id' => $incident->id,
                'status' => (string) $incident->getRawOriginal('status'),
                'failure_type' => $incident->failure_type,
                'root_cause_category' => $incident->root_cause_category,
                'detected_at' => $this->timestampToIso8601(
                    $incident->getRawOriginal('detected_at'),
                ),
                'project' => $incident->project === null
                    ? null
                    : [
                        'id' => $incident->project->id,
                        'name' => $incident->project->name,
                    ],
                'task' => $incident->task === null
                    ? null
                    : [
                        'key' => $incident->task->key,
                        'title' => $incident->task->title,
                        'project_id' => $incident->task->project_id,
                    ],
            ];
        }

        /** @var list<array<string, mixed>> $recentEventPayload */
        $recentEventPayload = [];

        $recentEvents = AuditEvent::query()
            ->with('project:id,name')
            ->latest('occurred_at')
            ->limit(6)
            ->get(['id', 'project_id', 'event_type', 'occurred_at']);

        foreach ($recentEvents as $event) {
            $recentEventPayload[] = [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'occurred_at' => $this->timestampToIso8601(
                    $event->getRawOriginal('occurred_at'),
                ),
                'project' => $event->project === null
                    ? null
                    : [
                        'id' => $event->project->id,
                        'name' => $event->project->name,
                    ],
            ];
        }

        return Inertia::render('agents/index', [
            'agents' => $agentPayload,
            'system' => [
                'total_agents' => $agents->count(),
                'enabled_agents' => $agents
                    ->filter(fn (Agent $agent): bool => $agent->enabled)
                    ->count(),
                'open_incidents' => RecoveryIncident::query()
                    ->whereIn('status', $openStatuses)
                    ->count(),
                'active_recoveries' => RecoveryIncident::query()
                    ->whereIn('status', $activeStatuses)
                    ->count(),
            ],
            'recent_events' => $recentEventPayload,
            'active_incidents' => $activeIncidentPayload,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function show(Agent $agent, AgentHarnessResolver $harnesses): Response
    {
        abort_unless($agent->project_id === null, 404);

        $agent->setAttribute('invoke_in_progress', $this->invokeInProgress($agent));

        $incidents = RecoveryIncident::query()
            ->whereHas(
                'recoveryRuns',
                fn (Builder $query) => $query->where('agent_id', $agent->id),
            )
            ->with([
                'project:id,name',
                'task:id,key,title,project_id',
                'recoveryRuns' => function (Relation $relation) use ($agent): void {
                    $relation->getQuery()
                        ->where('agent_id', $agent->id)
                        ->latest('started_at');
                },
            ])
            ->latest('detected_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (RecoveryIncident $incident): array => [
                'id' => $incident->id,
                'status' => $incident->status,
                'root_cause_category' => $incident->root_cause_category,
                'detected_at' => $incident->detected_at,
                'resolved_at' => $incident->resolved_at,
                'escalation_reason' => $incident->escalation_reason,
                'project' => $incident->project,
                'task' => $incident->task,
                'latest_run_id' => $incident->recoveryRuns->first()?->id,
            ]);

        return Inertia::render('agents/show', [
            'agent' => $agent,
            'incidents' => $incidents,
            'harness_capabilities' => fn (): array => $harnesses->capabilities(),
        ]);
    }

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

    public function showRun(
        Agent $agent,
        AgentRun $run,
        AgentRunRecorder $runs,
    ): Response {
        abort_unless($agent->project_id === null, 404);
        abort_unless($run->agent_id === $agent->id, 404);

        $run->loadMissing('task:id,key,title', 'project:id,name');
        $run->setAttribute('agent_messages', $runs->agentMessages($run));
        $run->makeHidden('log_path');

        return Inertia::render('agents/run-show', [
            'agent' => $agent->only(['id', 'name', 'role']),
            'agent_run' => $run,
        ]);
    }

    /**
     * Whether the Workflow Recovery Engineer is actively diagnosing,
     * repairing, or validating a recovery incident.
     */
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

    private function isRecoveryEngineer(Agent $agent): bool
    {
        return AgentRole::tryFrom(
            (string) $agent->getRawOriginal('role'),
        ) === AgentRole::RecoveryEngineer;
    }

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

    private function timestampToIso8601(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->toIso8601String();
    }

    private function nullableTimestampToIso8601(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->timestampToIso8601($value);
    }
}
