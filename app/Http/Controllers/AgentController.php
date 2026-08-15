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
use Illuminate\Http\RedirectResponse;
use LogicException;

class AgentController extends Controller
{
    public function store(
        StoreAgentRequest $request,
        Project $project,
    ): RedirectResponse {
        $project->agents()->create($request->validated());

        return to_route('projects.show', $project);
    }

    public function update(
        UpdateAgentRequest $request,
        Project $project,
        Agent $agent,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);

        $agent->update($request->validated());

        return to_route('projects.show', $project);
    }

    public function assignSkill(
        AssignAgentSkillRequest $request,
        Project $project,
        Agent $agent,
        AssignSkillToAgent $assignSkillToAgent,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);

        $skill = Skill::query()->findOrFail($request->validated('skill_id'));

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

        $agent->skills()->detach($skill->id);

        return to_route('projects.show', $project);
    }

    public function bindWorker(
        BindAgentWorkerRequest $request,
        Project $project,
        Agent $agent,
        BindAgentWorker $bindAgentWorker,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);

        $worker = AgentWorker::query()->findOrFail($request->validated('agent_worker_id'));

        try {
            $bindAgentWorker->handle($worker, $agent);
        } catch (LogicException $exception) {
            return back()->withErrors(['agent_worker_id' => $exception->getMessage()]);
        }

        return to_route('projects.show', $project);
    }
}
