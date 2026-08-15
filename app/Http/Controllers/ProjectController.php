<?php

namespace App\Http\Controllers;

use App\Actions\CreateProject;
use App\Actions\RecordProjectManagerMessage;
use App\Actions\RecordTaskOperatorMessage;
use App\Actions\RequeueBlockedTask;
use App\Actions\SetProjectStatus;
use App\Actions\StoreRoadmap;
use App\AgentRole;
use App\Http\Requests\StoreProjectManagerMessageRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\StoreRoadmapRequest;
use App\Http\Requests\StoreTaskOperatorMessageRequest;
use App\Http\Requests\UpdateProjectStatusRequest;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\TokenUsageObservability;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('projects/index', ['projects' => Project::query()->latest()->get(['id', 'name', 'path', 'status', 'git_status', 'updated_at'])]);
    }

    public function store(StoreProjectRequest $request, CreateProject $createProject): RedirectResponse
    {
        $project = $createProject->handle($request->string('name')->trim()->toString(), $request->string('path')->trim()->toString(), $request->string('mode')->toString() === 'existing');

        return to_route('projects.show', $project);
    }

    public function show(Project $project, Request $request, AuditLogger $audit, TokenUsageObservability $tokens, AgentRunRecorder $runs): Response
    {
        if ($request->session()->get('aios.selected_project_id') !== $project->id) {
            $request->session()->put('aios.selected_project_id', $project->id);
            $audit->record('project.selected', [], $project);
        }

        return Inertia::render('projects/show', [
            'project' => fn (): Project => $this->projectPayload($project, $tokens, $runs),
        ]);
    }

    private function projectPayload(Project $project, TokenUsageObservability $tokens, AgentRunRecorder $runs): Project
    {
        $project->load([
            'roadmaps' => fn ($query) => $query->latest(),
            'tasks' => fn ($query) => $query->orderBy('position')->with(['attempts' => fn ($attempts) => $attempts->latest('number')->limit(1), 'reviews' => fn ($reviews) => $reviews->latest()->limit(1)]),
            'auditEvents' => fn ($query) => $query->latest('occurred_at')->limit(20),
        ]);
        $project->loadSum('runs', 'token_usage');
        $project->setAttribute('token_usage_total', (int) ($project->runs_sum_token_usage ?? 0));
        $project->setAttribute('token_observability', $tokens->forProject($project));
        $project->setAttribute('office_workers', $this->officeWorkers($project, $runs));
        $recentRuns = $project->runs()
            ->select(['id', 'project_id', 'task_id', 'role', 'status', 'attempt_number', 'token_usage', 'exit_code', 'live_output', 'log_path', 'started_at', 'finished_at'])
            ->latest('started_at')
            ->limit(8)
            ->get();
        $recentRuns->each(function (AgentRun $run) use ($runs): void {
            $run->setAttribute('failure_reason', $run->getRawOriginal('status') === 'failed' ? $runs->failureReason($run) : null);
            $run->makeHidden(['live_output', 'log_path']);
        });
        $project->setRelation('recent_agent_runs', $recentRuns);

        return $project;
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     role: string,
     *     status: string,
     *     last_heartbeat_at: ?string,
     *     lease_state: string,
     *     run: ?array{id: int, status: string, attempt_number: ?int, started_at: ?string, finished_at: ?string, failure_reason: ?string},
     *     task: ?array{id: int, key: string, title: string, status: string}
     * }>
     */
    private function officeWorkers(Project $project, AgentRunRecorder $runs): array
    {
        $workers = $project->workers()
            ->select(['id', 'project_id', 'role', 'status', 'last_heartbeat_at', 'lease_expires_at'])
            ->orderBy('role')
            ->with(['runs' => fn ($query) => $query
                ->select(['id', 'project_id', 'task_id', 'agent_worker_id', 'role', 'status', 'attempt_number', 'live_output', 'log_path', 'started_at', 'finished_at'])
                ->latest('started_at')
                ->limit(1)
                ->with('task:id,key,title,status')])
            ->get();
        $officeWorkers = [];

        foreach ($workers as $worker) {
            $run = $worker->runs->first();
            $task = $run?->task;
            $leaseExpiresAt = $worker->getAttribute('lease_expires_at');

            $officeWorkers[] = [
                'id' => $worker->id,
                'role' => $worker->getRawOriginal('role'),
                'status' => $worker->status,
                'last_heartbeat_at' => $this->serializeDateAttribute($worker, 'last_heartbeat_at'),
                'lease_state' => ! $leaseExpiresAt instanceof CarbonInterface
                    ? 'none'
                    : ($leaseExpiresAt->isFuture() ? 'active' : 'expired'),
                'run' => $run === null ? null : [
                    'id' => $run->id,
                    'status' => $run->getRawOriginal('status'),
                    'attempt_number' => $run->attempt_number,
                    'started_at' => $this->serializeDateAttribute($run, 'started_at'),
                    'finished_at' => $this->serializeDateAttribute($run, 'finished_at'),
                    'failure_reason' => $run->getRawOriginal('status') === 'failed' ? $runs->failureReason($run) : null,
                ],
                'task' => $task === null ? null : [
                    'id' => $task->id,
                    'key' => $task->key,
                    'title' => $task->title,
                    'status' => $task->getRawOriginal('status'),
                ],
            ];
        }

        return $officeWorkers;
    }

    private function serializeDateAttribute(AgentRun|AgentWorker $model, string $attribute): ?string
    {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface ? $value->toISOString() : null;
    }

    public function updateStatus(UpdateProjectStatusRequest $request, Project $project, SetProjectStatus $setProjectStatus): RedirectResponse
    {
        $setProjectStatus->handle($project, ProjectStatus::from($request->validated('status')));

        return to_route('projects.show', $project);
    }

    public function requeueTask(Project $project, Task $task, RequeueBlockedTask $requeueBlockedTask): RedirectResponse
    {
        abort_unless($task->project_id === $project->id, 404);
        $requeueBlockedTask->handle($task);

        return to_route('projects.show', $project);
    }

    public function showTask(Project $project, Task $task, AgentRunRecorder $runs): Response
    {
        abort_unless($task->project_id === $project->id, 404);

        $task->load([
            'phase',
            'dependencies:id,key,title,status',
            'attempts' => fn ($query) => $query->latest('number'),
            'reviews' => fn ($query) => $query->latest()->with(['attempt:id,number,commit_sha', 'findings']),
            'runs' => fn ($query) => $query->select(['id', 'task_id', 'role', 'status', 'attempt_number', 'live_output', 'log_path', 'exit_code', 'started_at', 'finished_at'])->latest('started_at'),
            'operatorMessages' => fn ($query) => $query->oldest()->with('user:id,name'),
            'auditEvents' => fn ($query) => $query->latest('occurred_at')->limit(30),
        ]);
        $task->runs->each(function ($run) use ($runs): void {
            $transcript = $runs->transcript($run);
            $run->setAttribute('live_output', $transcript);
            $run->setAttribute('transcript', $transcript);
            $run->makeHidden('log_path');
        });

        return Inertia::render('projects/tasks/show', ['project' => $project->only(['id', 'name', 'path']), 'task' => $task]);
    }

    public function showAgentRun(Project $project, AgentRun $run, AgentRunRecorder $runs): Response
    {
        $run->loadMissing('task:id,key,title', 'worker:id,role,status,last_heartbeat_at');
        $run->setAttribute('agent_messages', $runs->agentMessages($run));
        $run->makeHidden('log_path');
        $isProjectManager = $run->getRawOriginal('role') === AgentRole::ProjectManager->value;

        return Inertia::render('projects/agent-runs/show', [
            'project' => $project->only(['id', 'name', 'path']),
            'agent_run' => $run,
            'project_manager_messages' => $isProjectManager
                ? $project->projectManagerMessages()->oldest()->with('user:id,name')->get()
                : [],
        ]);
    }

    public function storeProjectManagerMessage(StoreProjectManagerMessageRequest $request, Project $project, RecordProjectManagerMessage $messages): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $messages->handle($project, $user, $request->validated('body'));

        return back();
    }

    public function storeOperatorMessage(StoreTaskOperatorMessageRequest $request, Project $project, Task $task, RecordTaskOperatorMessage $recordTaskOperatorMessage): RedirectResponse
    {
        abort_unless($task->project_id === $project->id, 404);

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $recordTaskOperatorMessage->handle($task, $user, AgentRole::from($validated['recipient_role']), $validated['body']);

        return to_route('projects.tasks.show', [$project, $task]);
    }

    public function storeRoadmap(StoreRoadmapRequest $request, Project $project, StoreRoadmap $storeRoadmap): RedirectResponse
    {
        $file = $request->file('roadmap');

        if (! $file instanceof UploadedFile) {
            abort(422, 'A roadmap file is required.');
        }

        $storeRoadmap->handle($project, $file);

        return to_route('projects.show', $project);
    }
}
