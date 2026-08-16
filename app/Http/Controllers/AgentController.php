<?php

namespace App\Http\Controllers;

use App\Actions\AssignSkillToAgent;
use App\Actions\BindAgentWorker;
use App\Actions\ReorderAgentSkills;
use App\Http\Requests\AssignAgentSkillRequest;
use App\Http\Requests\BindAgentWorkerRequest;
use App\Http\Requests\ReorderAgentSkillsRequest;
use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Skill;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use LogicException;

class AgentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(
        StoreAgentRequest $request,
        Project $project,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $project): void {
            $agent = $project->agents()->create($request->validated());

            $this->audit->record('agent.created', [
                'project_id' => $project->id,
                'agent_id' => $agent->id,
                'configuration_version' => $agent->configuration_version,
                'role' => $agent->role->value,
                'harness' => $agent->harness->value,
            ], $project);
        }, attempts: 3);

        return to_route('projects.show', $project);
    }

    public function update(
        UpdateAgentRequest $request,
        Project $project,
        Agent $agent,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);

        DB::transaction(function () use ($request, $project, $agent): void {
            $previousVersion = $agent->configuration_version;
            $wasEnabled = $agent->enabled;

            $agent->update($request->validated());
            $agent->refresh();

            if ($agent->configuration_version === $previousVersion) {
                return;
            }

            $payload = [
                'project_id' => $project->id,
                'agent_id' => $agent->id,
                'previous_configuration_version' => $previousVersion,
                'configuration_version' => $agent->configuration_version,
                'role' => $agent->role->value,
                'harness' => $agent->harness->value,
            ];

            $this->audit->record('agent.updated', $payload, $project);

            if ($wasEnabled !== $agent->enabled) {
                $this->audit->record(
                    $agent->enabled ? 'agent.enabled' : 'agent.disabled',
                    $payload,
                    $project,
                );
            }
        }, attempts: 3);

        return to_route('projects.show', $project);
    }

    public function assignSkill(
        AssignAgentSkillRequest $request,
        Project $project,
        Agent $agent,
        AssignSkillToAgent $assignSkillToAgent,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);

        $skill = Skill::query()->findOrFail((int) $request->validated('skill_id'));

        try {
            $assignSkillToAgent->handle($agent, $skill);
        } catch (LogicException $exception) {
            return back()->withErrors(['skill_id' => $exception->getMessage()]);
        }

        return to_route('projects.show', $project);
    }

    public function reorderSkills(
        ReorderAgentSkillsRequest $request,
        Project $project,
        Agent $agent,
        ReorderAgentSkills $reorderAgentSkills,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);

        $reorderAgentSkills->handle($agent, $request->validated('skill_ids'));

        return to_route('projects.show', $project);
    }

    public function unassignSkill(
        Project $project,
        Agent $agent,
        Skill $skill,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);
        abort_unless($skill->project_id === $project->id, 404);

        DB::transaction(function () use ($project, $agent, $skill): void {
            $detached = $agent->skills()->detach($skill->id);

            if ($detached === 0) {
                return;
            }

            $this->audit->record('skill.unassigned', [
                'project_id' => $project->id,
                'agent_id' => $agent->id,
                'agent_configuration_version' => $agent->configuration_version,
                'skill_id' => $skill->id,
                'skill_version' => $skill->version,
            ], $project);
        }, attempts: 3);

        return to_route('projects.show', $project);
    }

    public function bindWorker(
        BindAgentWorkerRequest $request,
        Project $project,
        Agent $agent,
        BindAgentWorker $bindAgentWorker,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);

        $worker = AgentWorker::query()->findOrFail((int) $request->validated('agent_worker_id'));

        try {
            $bindAgentWorker->handle($worker, $agent);
        } catch (LogicException $exception) {
            return back()->withErrors(['agent_worker_id' => $exception->getMessage()]);
        }

        return to_route('projects.show', $project);
    }
}
