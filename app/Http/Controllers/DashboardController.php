<?php

namespace App\Http\Controllers;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\AuditEvent;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\ProjectStatus;
use App\TaskStatus;
use App\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $selectedProject = $this->selectedProject($request);

        return Inertia::render('dashboard', [
            'summary' => $this->summary(),
            'projects' => $this->projects(),
            'workflow' => $this->workflow(),
            'agent_console' => $this->agentConsole($selectedProject),
            'recent_activity' => $this->recentActivity(),
            'open_tickets' => $this->openTickets(),
            'generated_at' => now()->toISOString(),
        ]);
    }

    /**
     * @return array{
     *     active_projects: int,
     *     open_tasks: int,
     *     enabled_agents: int,
     *     running_executions: int,
     *     open_tickets: int,
     *     active_workers: int
     * }
     */
    private function summary(): array
    {
        return [
            'active_projects' => Project::query()
                ->where('status', ProjectStatus::Running->value)
                ->count(),
            'open_tasks' => Task::query()
                ->notCleared()
                ->whereNotIn('status', [
                    TaskStatus::Done->value,
                    TaskStatus::Cancelled->value,
                ])
                ->count(),
            'enabled_agents' => Agent::query()
                ->whereNotNull('project_id')
                ->where('enabled', true)
                ->count(),
            'running_executions' => AgentRun::query()
                ->where('status', AgentRunStatus::Running->value)
                ->count(),
            'open_tickets' => Ticket::query()
                ->whereNotIn('status', [
                    TicketStatus::Closed->value,
                    TicketStatus::Converted->value,
                ])
                ->count(),
            'active_workers' => AgentWorker::query()
                ->whereIn('status', ['working', 'recovering'])
                ->count(),
        ];
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     status: string,
     *     git_status: string,
     *     task_count: int,
     *     done_tasks: int,
     *     open_tasks: int,
     *     progress_percent: int,
     *     current_phase: ?array{id: int, title: string, position: int},
     *     updated_at: ?string
     * }>
     */
    private function projects(): array
    {
        $projects = Project::query()
            ->select([
                'id',
                'name',
                'status',
                'git_status',
                'updated_at',
            ])
            ->withCount([
                'tasks as task_count' => fn ($query) => $query->notCleared(),
                'tasks as done_task_count' => fn ($query) => $query
                    ->notCleared()
                    ->where('status', TaskStatus::Done->value),
                'tasks as open_task_count' => fn ($query) => $query
                    ->notCleared()
                    ->whereNotIn('status', [
                        TaskStatus::Done->value,
                        TaskStatus::Cancelled->value,
                    ]),
            ])
            ->with([
                'phases' => fn ($query) => $query
                    ->select([
                        'id',
                        'project_id',
                        'position',
                        'title',
                    ])
                    ->orderBy('position')
                    ->with([
                        'tasks' => fn ($tasks) => $tasks
                            ->notCleared()
                            ->select([
                                'id',
                                'phase_id',
                                'position',
                                'status',
                            ])
                            ->orderBy('position'),
                    ]),
            ])
            ->latest('updated_at')
            ->limit(4)
            ->get();

        return $projects
            ->map(function (Project $project): array {
                $taskCount = (int) $project->getAttribute('task_count');
                $doneTasks = (int) $project->getAttribute(
                    'done_task_count',
                );
                $openTasks = (int) $project->getAttribute(
                    'open_task_count',
                );
                $currentPhase = $this->currentPhase($project);

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->getRawOriginal('status'),
                    'git_status' => $project->git_status,
                    'task_count' => $taskCount,
                    'done_tasks' => $doneTasks,
                    'open_tasks' => $openTasks,
                    'progress_percent' => $taskCount > 0
                        ? (int) round(($doneTasks / $taskCount) * 100)
                        : 0,
                    'current_phase' => $currentPhase === null
                        ? null
                        : [
                            'id' => $currentPhase->id,
                            'title' => $currentPhase->title,
                            'position' => (int) $currentPhase->position,
                        ],
                    'updated_at' => $this->serializeDate(
                        $project->updated_at,
                    ),
                ];
            })
            ->values()
            ->all();
    }

    private function currentPhase(Project $project): ?Phase
    {
        $phase = $project->phases->first(
            function (Phase $phase): bool {
                return $phase->tasks->contains(
                    fn (Task $task): bool => ! in_array(
                        $task->getRawOriginal('status'),
                        [
                            TaskStatus::Done->value,
                            TaskStatus::Cancelled->value,
                        ],
                        true,
                    ),
                );
            },
        );

        if ($phase instanceof Phase) {
            return $phase;
        }

        $lastPhase = $project->phases->last();

        return $lastPhase instanceof Phase ? $lastPhase : null;
    }

    /**
     * @return array{
     *     queued: int,
     *     coding: int,
     *     validating: int,
     *     ready_for_review: int,
     *     reviewing: int,
     *     changes_required: int,
     *     done: int,
     *     blocked: int
     * }
     */
    private function workflow(): array
    {
        $counts = Task::query()
            ->notCleared()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'queued' => (int) $counts->get(
                TaskStatus::Queued->value,
                0,
            ),
            'coding' => (int) $counts->get(
                TaskStatus::Coding->value,
                0,
            ),
            'validating' => (int) $counts->get(
                TaskStatus::Validating->value,
                0,
            ),
            'ready_for_review' => (int) $counts->get(
                TaskStatus::ReadyForReview->value,
                0,
            ),
            'reviewing' => (int) $counts->get(
                TaskStatus::Reviewing->value,
                0,
            ),
            'changes_required' => (int) $counts->get(
                TaskStatus::ChangesRequired->value,
                0,
            ),
            'done' => (int) $counts->get(
                TaskStatus::Done->value,
                0,
            ),
            'blocked' => (int) $counts->get(
                TaskStatus::Blocked->value,
                0,
            ),
        ];
    }

    /**
     * @return array{
     *     project: ?array{id: int, name: string, status: string},
     *     agents: array<int, array{
     *         id: string,
     *         scope: 'project'|'system',
     *         project_id: ?int,
     *         name: string,
     *         role: string,
     *         harness: ?string,
     *         model: ?string,
     *         enabled: bool,
     *         runtime_status: string,
     *         runtime_source: string,
     *         last_heartbeat_at: ?string,
     *         last_run: ?array{
     *             id: int,
     *             status: string,
     *             started_at: ?string,
     *             finished_at: ?string,
     *             task: ?array{id: int, key: string, title: string}
     *         }
     *     }>
     * }
     */
    private function agentConsole(?Project $project): array
    {
        $agents = [];

        if ($project !== null) {
            $workers = $project->workers()
                ->select([
                    'id',
                    'project_id',
                    'role',
                    'agent_id',
                    'status',
                    'last_heartbeat_at',
                ])
                ->with([
                    'agent' => fn ($query) => $query->select([
                        'id',
                        'project_id',
                        'name',
                        'role',
                        'harness',
                        'model',
                        'enabled',
                    ]),
                    'runs' => fn ($query) => $query
                        ->select([
                            'id',
                            'project_id',
                            'task_id',
                            'agent_worker_id',
                            'agent_id',
                            'status',
                            'started_at',
                            'finished_at',
                        ])
                        ->latest('started_at')
                        ->limit(1)
                        ->with('task:id,key,title'),
                ])
                ->orderBy('role')
                ->get();

            foreach ($workers as $worker) {
                $agent = $worker->agent;
                $run = $worker->runs->first();

                $agents[] = [
                    'id' => 'worker-'.$worker->id,
                    'scope' => 'project',
                    'project_id' => $project->id,
                    'name' => $agent instanceof Agent
                        ? $agent->name
                        : $this->defaultRoleName(
                            $worker->getRawOriginal('role'),
                        ),
                    'role' => $worker->getRawOriginal('role'),
                    'harness' => $agent instanceof Agent
                        ? $this->stringAttribute($agent, 'harness')
                        : null,
                    'model' => $agent instanceof Agent
                        ? $agent->model
                        : null,
                    'enabled' => $agent instanceof Agent
                        && $agent->enabled,
                    'runtime_status' => (string) $worker->status,
                    'runtime_source' => 'agent_worker',
                    'last_heartbeat_at' => $this->serializeDate(
                        $worker->last_heartbeat_at,
                    ),
                    'last_run' => $this->runPayload(
                        $run instanceof AgentRun ? $run : null,
                    ),
                ];
            }
        }

        $recoveryAgent = Agent::query()
            ->whereNull('project_id')
            ->where(
                'role',
                AgentRole::RecoveryEngineer->value,
            )
            ->where('enabled', true)
            ->select([
                'id',
                'project_id',
                'name',
                'role',
                'harness',
                'model',
                'enabled',
            ])
            ->with([
                'runs' => fn ($query) => $query
                    ->select([
                        'id',
                        'project_id',
                        'task_id',
                        'agent_id',
                        'status',
                        'started_at',
                        'finished_at',
                    ])
                    ->latest('started_at')
                    ->limit(1)
                    ->with('task:id,key,title'),
            ])
            ->first();

        if ($recoveryAgent !== null) {
            $recoveryRun = $recoveryAgent->runs->first();

            $agents[] = [
                'id' => 'agent-'.$recoveryAgent->id,
                'scope' => 'system',
                'project_id' => null,
                'name' => $recoveryAgent->name,
                'role' => $recoveryAgent->getRawOriginal('role'),
                'harness' => $this->stringAttribute(
                    $recoveryAgent,
                    'harness',
                ),
                'model' => $recoveryAgent->model,
                'enabled' => $recoveryAgent->enabled,
                'runtime_status' => $recoveryRun instanceof AgentRun
                    ? $recoveryRun->getRawOriginal('status')
                    : 'not_started',
                'runtime_source' => 'latest_agent_run',
                'last_heartbeat_at' => null,
                'last_run' => $this->runPayload(
                    $recoveryRun instanceof AgentRun
                        ? $recoveryRun
                        : null,
                ),
            ];
        }

        return [
            'project' => $project === null
                ? null
                : [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->getRawOriginal('status'),
                ],
            'agents' => $agents,
        ];
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     event_type: string,
     *     occurred_at: ?string,
     *     project: array{id: int, name: string},
     *     task: ?array{id: int, key: string, title: string}
     * }>
     */
    private function recentActivity(): array
    {
        return AuditEvent::query()
            ->select([
                'id',
                'project_id',
                'task_id',
                'event_type',
                'occurred_at',
            ])
            ->with([
                'project:id,name',
                'task:id,key,title',
            ])
            ->latest('occurred_at')
            ->limit(6)
            ->get()
            ->map(function (AuditEvent $event): array {
                $task = $event->task;

                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'occurred_at' => $this->serializeDate(
                        $event->occurred_at,
                    ),
                    'project' => [
                        'id' => $event->project->id,
                        'name' => $event->project->name,
                    ],
                    'task' => $task instanceof Task
                        ? [
                            'id' => $task->id,
                            'key' => $task->key,
                            'title' => $task->title,
                        ]
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     key: string,
     *     title: string,
     *     status: string,
     *     category: ?string,
     *     priority: ?string,
     *     updated_at: ?string,
     *     project: array{id: int, name: string}
     * }>
     */
    private function openTickets(): array
    {
        return Ticket::query()
            ->select([
                'id',
                'project_id',
                'key',
                'title',
                'status',
                'category',
                'ai_suggested_priority',
                'final_priority',
                'updated_at',
            ])
            ->whereNotIn('status', [
                TicketStatus::Closed->value,
                TicketStatus::Converted->value,
            ])
            ->with('project:id,name')
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(function (Ticket $ticket): array {
                $finalPriority = $ticket->getRawOriginal(
                    'final_priority',
                );
                $suggestedPriority = $ticket->getRawOriginal(
                    'ai_suggested_priority',
                );

                return [
                    'id' => $ticket->id,
                    'key' => $ticket->key,
                    'title' => $ticket->title,
                    'status' => $ticket->getRawOriginal('status'),
                    'category' => $this->stringAttribute(
                        $ticket,
                        'category',
                    ),
                    'priority' => is_string($finalPriority)
                        ? $finalPriority
                        : (
                            is_string($suggestedPriority)
                                ? $suggestedPriority
                                : null
                        ),
                    'updated_at' => $this->serializeDate(
                        $ticket->updated_at,
                    ),
                    'project' => [
                        'id' => $ticket->project->id,
                        'name' => $ticket->project->name,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function selectedProject(Request $request): ?Project
    {
        $selectedProjectId = $request->session()->get(
            'aios.selected_project_id',
        );

        if (is_numeric($selectedProjectId)) {
            $selectedProject = Project::query()->find(
                (int) $selectedProjectId,
            );

            if ($selectedProject !== null) {
                return $selectedProject;
            }
        }

        return Project::query()
            ->where('status', ProjectStatus::Running->value)
            ->latest('updated_at')
            ->first()
            ?? Project::query()
                ->latest('updated_at')
                ->first();
    }

    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     started_at: ?string,
     *     finished_at: ?string,
     *     task: ?array{id: int, key: string, title: string}
     * }|null
     */
    private function runPayload(?AgentRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        $task = $run->task;

        return [
            'id' => $run->id,
            'status' => $run->getRawOriginal('status'),
            'started_at' => $this->serializeDate(
                $run->started_at,
            ),
            'finished_at' => $this->serializeDate(
                $run->finished_at,
            ),
            'task' => $task instanceof Task
                ? [
                    'id' => $task->id,
                    'key' => $task->key,
                    'title' => $task->title,
                ]
                : null,
        ];
    }

    private function defaultRoleName(string $role): string
    {
        return match ($role) {
            AgentRole::ProjectManager->value => 'Project Manager',
            AgentRole::Coder->value => 'Coder',
            AgentRole::Reviewer->value => 'Reviewer',
            default => str($role)
                ->replace('_', ' ')
                ->title()
                ->toString(),
        };
    }

    private function stringAttribute(
        Agent|Ticket $model,
        string $attribute,
    ): ?string {
        $value = $model->getRawOriginal($attribute);

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private function serializeDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface
            ? $value->toISOString()
            : null;
    }
}
