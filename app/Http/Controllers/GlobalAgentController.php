<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGlobalAgentRequest;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\RecoveryIncident;
use App\RecoveryIncidentStatus;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GlobalAgentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $openStatuses = array_map(fn (RecoveryIncidentStatus $status): string => $status->value, RecoveryIncidentStatus::open());

        $agents = Agent::query()->whereNull('project_id')->orderBy('name')->get();
        $agents->each(function (Agent $agent) use ($openStatuses): void {
            $agent->setAttribute('open_incident_count', RecoveryIncident::query()
                ->whereHas('recoveryRuns', fn (Builder $query) => $query->where('agent_id', $agent->id))
                ->whereIn('status', $openStatuses)
                ->count());
        });

        return Inertia::render('agents/index', ['agents' => $agents]);
    }

    public function show(Agent $agent): Response
    {
        abort_unless($agent->project_id === null, 404);

        $incidents = RecoveryIncident::query()
            ->whereHas('recoveryRuns', fn (Builder $query) => $query->where('agent_id', $agent->id))
            ->with([
                'project:id,name',
                'task:id,key,title,project_id',
                'recoveryRuns' => fn (Builder $query) => $query->where('agent_id', $agent->id)->latest('started_at'),
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
}
