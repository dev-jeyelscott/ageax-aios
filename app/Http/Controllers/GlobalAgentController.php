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

        $agentPayload = $agents
            ->map(function (Agent $agent) use ($openStatuses): array {
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

                return [
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
                            'started_at' => $latestRun->started_at->toIso8601String(),
                            'finished_at' => $latestRun->finished_at?->toIso8601String(),
                        ],
                    'recent_activity' => $recentRuns
                        ->reverse()
                        ->values()
                        ->map(fn (AgentRun $run): array => [
                            'id' => $run->id,
                            'status' => (string) $run->getRawOriginal('status'),
                            'started_at' => $run->started_at->toIso8601String(),
                            'finished_at' => $run->finished_at?->toIso8601String(),
                            'duration_seconds' => $this->runDurationSeconds($run),
                        ])
                        ->all(),
                ];
            })
            ->values();

        $activeIncidents = RecoveryIncident::query()
            ->whereIn('status', $openStatuses)
            ->with([
                'project:id,name',
                'task:id,key,title,project_id',
            ])
            ->latest('detected_at')
            ->limit(5)
            ->get()
            ->map(fn (RecoveryIncident $incident): array => [
                'id' => $incident->id,
                'status' => (string) $incident->getRawOriginal('status'),
                'failure_type' => $incident->failure_type,
                'root_cause_category' => $incident->root_cause_category,
                'detected_at' => $incident->detected_at->toIso8601String(),
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
            ])
            ->values();

        $recentEvents = AuditEvent::query()
            ->with('project:id,name')
            ->latest('occurred_at')
            ->limit(6)
            ->get(['id', 'project_id', 'event_type', 'occurred_at'])
            ->map(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'project' => $event->project === null
                    ? null
                    : [
                        'id' => $event->project->id,
                        'name' => $event->project->name,
                    ],
            ])
            ->values();

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
            'recent_events' => $recentEvents,
            'active_incidents' => $activeIncidents,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function show(Agent $agent, AgentHarnessResolver $harnesses): Response
    {
        abort_unless($agent->project_id === null, 404);

        $agent->setAttribute('invoke_in_progress', $this->invokeInProgress($agent));

        $incidents = RecoveryIncident::query()
            ->whereHas('recoveryRuns', fn (Builder $query) => $query->where('agent_id', $agent->id))
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
                $this->audit->record($lockedAgent->enabled ? 'agent.enabled' : 'agent.disabled', $payload);
            }
        }, attempts: 3);

        return to_route('agents.show', $agent);
    }

    public function invoke(Agent $agent): RedirectResponse
    {
        abort_unless($agent->project_id === null, 404);
        abort_unless($this->isRecoveryEngineer($agent), 404);
        abort_unless($agent->enabled, 422, 'A disabled Agent cannot be invoked.');
        abort_if($this->invokeInProgress($agent), 409, 'The Workflow Recovery Engineer is already working.');

        RunWorkflowRecoveryEngineerNow::dispatch($agent->id);

        $this->audit->record('agent.invoke_requested', [
            'agent_id' => $agent->id,
            'role' => (string) $agent->getRawOriginal('role'),
        ]);

        return to_route('agents.show', $agent);
    }

    public function showRun(Agent $agent, AgentRun $run, AgentRunRecorder $runs): Response
    {
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

    /** Whether the Workflow Recovery Engineer is actively diagnosing, repairing, or validating a recovery incident right now, whether from the scheduled scan or a manual invocation. */
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
        return AgentRole::tryFrom((string) $agent->getRawOriginal('role')) === AgentRole::RecoveryEngineer;
    }

    private function runDurationSeconds(AgentRun $run): ?int
    {
        if ($run->finished_at === null) {
            return null;
        }

        return max(
            0,
            (int) round($run->started_at->diffInSeconds($run->finished_at)),
        );
    }
}
