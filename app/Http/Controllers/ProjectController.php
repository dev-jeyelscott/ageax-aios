<?php

namespace App\Http\Controllers;

use App\Actions\ApproveFeatureGoal;
use App\Actions\ClearProjectTasks;
use App\Actions\CreateProject;
use App\Actions\DeleteProject;
use App\Actions\PlanFeatureGoal;
use App\Actions\ProvisionDefaultProjectAgents;
use App\Actions\RecordProjectManagerMessage;
use App\Actions\RecordTaskOperatorMessage;
use App\Actions\RequestProjectReconciliation;
use App\Actions\RequeueBlockedRoadmap;
use App\Actions\RequeueBlockedTask;
use App\Actions\SetProjectStatus;
use App\Actions\SkipBlockedTask;
use App\Actions\StoreFeatureSpec;
use App\Actions\StoreRoadmap;
use App\AgentHarness;
use App\AgentRole;
use App\Http\Requests\SkipBlockedTaskRequest;
use App\Http\Requests\StoreFeatureSpecRequest;
use App\Http\Requests\StoreProjectManagerMessageRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\StoreRoadmapRequest;
use App\Http\Requests\StoreTaskOperatorMessageRequest;
use App\Http\Requests\UpdateGoalRunRequest;
use App\Http\Requests\UpdateProjectStatusRequest;
use App\Http\Requests\UpdateProjectStewardshipPolicyRequest;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\GoalRun;
use App\Models\Project;
use App\Models\ProjectReconciliationRun;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\ProjectReconciliationStatus;
use App\ProjectReconciliationTrigger;
use App\ProjectStatus;
use App\Services\AgentHarnessResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\TokenUsageNormalizer;
use App\Services\TokenUsageObservability;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('projects/index', [
            'projects' => Project::query()
                ->latest()
                ->get([
                    'id',
                    'name',
                    'path',
                    'status',
                    'git_status',
                    'updated_at',
                ]),
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        CreateProject $createProject,
    ): RedirectResponse {
        $project = $createProject->handle(
            $request->string('name')->trim()->toString(),
            $request->string('path')->trim()->toString(),
            $request->string('mode')->toString()
                === 'existing',
        );

        return to_route('projects.show', $project);
    }

    public function show(
        Project $project,
        Request $request,
        AuditLogger $audit,
        TokenUsageObservability $tokens,
        TokenUsageNormalizer $usageNormalizer,
        AgentRunRecorder $runs,
        AgentHarnessResolver $harnesses,
    ): Response {
        if (
            $request->session()->get(
                'aios.selected_project_id',
            ) !== $project->id
        ) {
            $request->session()->put(
                'aios.selected_project_id',
                $project->id,
            );
            $audit->record('project.selected', [], $project);
        }

        return Inertia::render('projects/show', [
            'project' => fn (): Project => $this->projectPayload(
                $project,
                $tokens,
                $runs,
                $this->usageWindow($request),
                $usageNormalizer,
            ),
            'harness_capabilities' => fn (): array => $harnesses->capabilities(),
        ]);
    }

    private function projectPayload(
        Project $project,
        TokenUsageObservability $tokens,
        AgentRunRecorder $runs,
        string $usageWindow,
        TokenUsageNormalizer $usageNormalizer,
    ): Project {
        $project->load([
            'featureSpecs' => fn ($query) => $query->latest(),
            'goalRuns' => fn ($query) => $query->latest()->with(['task', 'sessions.agent']),
            'roadmaps' => fn ($query) => $query->latest(),
            'tasks' => fn ($query) => $query
                ->notCleared()
                ->orderBy('position')
                ->with([
                    'attempts' => fn ($attempts) => $attempts
                        ->latest('number')
                        ->limit(1),
                    'reviews' => fn ($reviews) => $reviews
                        ->latest()
                        ->limit(1),
                ]),
            'auditEvents' => fn ($query) => $query
                ->latest('occurred_at')
                ->limit(20),
            'agents' => fn ($query) => $query
                ->with([
                    'skills' => fn ($skills) => $skills
                        ->select(
                            'skills.id',
                            'skills.name',
                            'skills.slug',
                            'skills.version',
                            'skills.enabled',
                        ),
                ])
                ->orderBy('role')
                ->orderBy('name'),
            'skills' => fn ($query) => $query->orderBy('name'),
            'workers' => fn ($query) => $query
                ->select([
                    'id',
                    'project_id',
                    'role',
                    'agent_id',
                    'status',
                    'last_heartbeat_at',
                ])
                ->orderBy('role'),
        ]);

        $defaultAgentNames = ProvisionDefaultProjectAgents::defaultNames();
        $project->agents->each(function ($agent) use ($defaultAgentNames): void {
            $agent->setAttribute(
                'is_default',
                ($defaultAgentNames[$agent->getRawOriginal('role')] ?? null)
                    === $agent->name,
            );
        });

        $usage = $this->harnessUsage($project, $usageWindow, $usageNormalizer);
        $project->setAttribute(
            'token_usage_total',
            $usage['total_processed_tokens'],
        );
        $project->setAttribute(
            'token_observability',
            $tokens->forProject($project),
        );
        $officeWorkers = $this->officeWorkers($project, $runs);
        $project->setAttribute(
            'office_workers',
            $officeWorkers,
        );
        $project->setAttribute(
            'office_workflow',
            $this->officeWorkflow($officeWorkers),
        );
        $project->setAttribute(
            'git_evidence',
            $this->gitEvidence($project),
        );
        $project->setAttribute(
            'reconciliation',
            $this->reconciliationPayload($project),
        );
        $stewardshipPolicy = $project->getAttribute('stewardship_policy');
        $stewardshipPolicy = is_array($stewardshipPolicy) ? $stewardshipPolicy : [];
        $project->setAttribute('stewardship', [
            'automatic_task_creation' => (bool) ($stewardshipPolicy['automatic_task_creation'] ?? false),
            'maintenance_task_count' => $project->tasks()->notCleared()->whereNotNull('knowledge_improvement_candidate_id')->count(),
        ]);
        $project->setAttribute(
            'harness_usage',
            $usage['harnesses'],
        );
        $project->setAttribute('token_usage_evidence', $usage);

        $recentRuns = $project->runs()
            ->select([
                'id',
                'project_id',
                'task_id',
                'role',
                'harness',
                'status',
                'attempt_number',
                'token_usage',
                'result',
                'exit_code',
                'live_output',
                'log_path',
                'started_at',
                'finished_at',
            ])
            ->latest('started_at')
            ->limit(8)
            ->get();

        $recentRuns->each(
            function (AgentRun $run) use ($runs): void {
                $result = $run->getAttribute('result');
                $tokenUsage = is_array($result) ? $result['token_usage'] ?? null : null;

                $run->setAttribute(
                    'failure_reason',
                    $run->getRawOriginal('status')
                        === 'failed'
                        ? $runs->failureReason($run)
                        : null,
                );
                $run->setAttribute(
                    'token_breakdown',
                    $this->tokenBreakdown($tokenUsage),
                );
                $run->makeHidden([
                    'live_output',
                    'log_path',
                    'result',
                ]);
            },
        );

        $project->setRelation(
            'recent_agent_runs',
            $recentRuns,
        );

        return $project;
    }

    public function storeFeatureSpec(Project $project, StoreFeatureSpecRequest $request, StoreFeatureSpec $store, PlanFeatureGoal $planner): RedirectResponse
    {
        $featureSpec = $store->handle($project, $request->file('feature'), $request->user());
        $planner->handle($featureSpec);

        return to_route('projects.show', $project);
    }

    public function approveFeatureGoal(Project $project, GoalRun $goalRun, UpdateGoalRunRequest $request, ApproveFeatureGoal $approve): RedirectResponse
    {
        abort_unless($goalRun->project_id === $project->id, 404);
        $approve->handle($goalRun, $request->validated('goal_text'));

        return to_route('projects.show', $project);
    }

    /**
     * @return array{input_tokens: int, input_includes_cached_tokens: bool, cache_creation_input_tokens: int, cache_read_input_tokens: int, output_tokens: int, total_tokens: int}|null
     */
    private function tokenBreakdown(mixed $tokenUsage): ?array
    {
        if (! is_array($tokenUsage) || ($tokenUsage['status'] ?? null) !== 'complete') {
            return null;
        }

        $inputTokens = $tokenUsage['input_tokens'] ?? null;
        $cacheCreationTokens = $tokenUsage['cache_creation_input_tokens'] ?? null;
        $cacheReadTokens = $tokenUsage['cache_read_input_tokens'] ?? null;
        $outputTokens = $tokenUsage['output_tokens'] ?? null;
        $totalTokens = $tokenUsage['canonical_total_tokens'] ?? null;

        if (! is_int($inputTokens) || ! is_int($cacheCreationTokens) || ! is_int($cacheReadTokens) || ! is_int($outputTokens) || ! is_int($totalTokens)) {
            return null;
        }

        return [
            'input_tokens' => $inputTokens,
            'input_includes_cached_tokens' => ($tokenUsage['canonical_total_method'] ?? null) === 'input_includes_cached_tokens_plus_output',
            'cache_creation_input_tokens' => $cacheCreationTokens,
            'cache_read_input_tokens' => $cacheReadTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
        ];
    }

    /**
     * @return array{
     *     task: array{id: int, key: string, title: string},
     *     attempt_number: int,
     *     status: string,
     *     base_sha: ?string,
     *     head_sha: ?string,
     *     commit_sha: ?string,
     *     changed_files: ?array<int, string>,
     *     validation_results: ?array<string, mixed>
     * }|null
     */
    private function gitEvidence(Project $project): ?array
    {
        $attempt = TaskAttempt::query()
            ->whereHas(
                'task',
                fn ($query) => $query->where(
                    'project_id',
                    $project->id,
                ),
            )
            ->with('task:id,key,title')
            ->latest('id')
            ->first([
                'id',
                'task_id',
                'number',
                'status',
                'base_sha',
                'head_sha',
                'commit_sha',
                'changed_files',
                'validation_results',
            ]);

        if ($attempt === null) {
            return null;
        }

        $task = $attempt->task;

        if (! $task instanceof Task) {
            return null;
        }

        $changedFiles = $attempt->getAttribute('changed_files');
        $validationResults = $attempt->getAttribute('validation_results');

        return [
            'task' => [
                'id' => $task->id,
                'key' => $task->key,
                'title' => $task->title,
            ],
            'attempt_number' => (int) $attempt->number,
            'status' => (string) $attempt->getRawOriginal('status'),
            'base_sha' => $attempt->getAttribute('base_sha'),
            'head_sha' => $attempt->getAttribute('head_sha'),
            'commit_sha' => $attempt->getAttribute('commit_sha'),
            'changed_files' => is_array($changedFiles)
                ? array_values($changedFiles)
                : null,
            'validation_results' => is_array($validationResults)
                ? $validationResults
                : null,
        ];
    }

    /**
     * @return array{
     *     latest: array{
     *         id: int,
     *         status: string,
     *         trigger: string,
     *         baseline_sha: ?string,
     *         evaluated_head_sha: ?string,
     *         working_tree_dirty: bool,
     *         started_at: ?string,
     *         finished_at: ?string,
     *         failure_reason: ?string,
     *         summary_counts: ?array<string, int>
     *     }|null,
     *     active: bool
     * }
     */
    private function reconciliationPayload(Project $project): array
    {
        $run = $project->reconciliationRuns()->latest('id')->first();

        if ($run === null) {
            return ['latest' => null, 'active' => false];
        }

        $status = (string) $run->getRawOriginal('status');
        $result = $run->getAttribute('result');
        $mechanicalResult = $run->getAttribute('mechanical_result');

        return [
            'latest' => [
                'id' => $run->id,
                'status' => $status,
                'trigger' => (string) $run->getRawOriginal('trigger'),
                'baseline_sha' => $run->baseline_sha,
                'evaluated_head_sha' => $run->evaluated_head_sha,
                'working_tree_dirty' => (bool) $run->working_tree_dirty,
                'started_at' => $this->serializeDateAttribute($run, 'started_at'),
                'finished_at' => $this->serializeDateAttribute($run, 'finished_at'),
                'failure_reason' => $run->failure_reason,
                'mechanical_result' => is_array($mechanicalResult) ? $mechanicalResult : null,
                'summary_counts' => is_array($result) ? [
                    'unchanged_functionality' => count($result['functionality_delta']['unchanged'] ?? []),
                    'added_functionality' => count($result['functionality_delta']['added'] ?? []),
                    'changed_functionality' => count($result['functionality_delta']['changed'] ?? []),
                    'removed_functionality' => count($result['functionality_delta']['removed'] ?? []),
                    'documentation_drift' => count($result['documentation_findings'] ?? []),
                    'resolved_drift' => count($result['resolved_drift'] ?? []),
                ] : null,
            ],
            'active' => in_array($status, [ProjectReconciliationStatus::Queued->value, ProjectReconciliationStatus::Running->value], true),
        ];
    }

    /**
     * @return array{window: array{key: string, label: string, starts_at: ?string}, run_count: int, token_usage_run_count: int, total_processed_tokens: int, average_tokens_per_run: ?int, legacy_incomplete_run_count: int, legacy_token_usage: int, harnesses: array<string, array<string, mixed>>}
     */
    private function harnessUsage(Project $project, string $window, TokenUsageNormalizer $normalizer): array
    {
        $startsAt = match ($window) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            default => null,
        };
        $runs = $project->runs()
            ->when($startsAt !== null, fn ($query) => $query->where('started_at', '>=', $startsAt))
            ->get(['id', 'harness', 'codex_run_id', 'token_usage', 'result', 'configuration_snapshot']);
        $harnesses = [];
        $legacyIncompleteRunCount = 0;
        $legacyTokenUsage = 0;

        foreach ($runs as $run) {
            $harness = $run->getRawOriginal('harness');
            $key = is_string($harness) && $harness !== ''
                ? $harness
                : (filled($run->codex_run_id) ? AgentHarness::Codex->value : 'legacy');
            $harnesses[$key] ??= [
                'run_count' => 0,
                'token_usage_run_count' => 0,
                'token_usage' => null,
                'known_token_usage' => 0,
                'legacy_incomplete_run_count' => 0,
                'legacy_token_usage' => 0,
                'configurations' => [],
            ];
            $harnesses[$key]['run_count']++;
            $total = $normalizer->canonicalTotal($run);

            if ($total === null) {
                $harnesses[$key]['legacy_incomplete_run_count']++;
                $legacyIncompleteRunCount++;
                $legacyValue = $run->getAttribute('token_usage');
                $legacyValue = is_int($legacyValue) ? $legacyValue : 0;
                $harnesses[$key]['legacy_token_usage'] += $legacyValue;
                $legacyTokenUsage += $legacyValue;

                continue;
            }

            $harnesses[$key]['token_usage_run_count']++;
            $harnesses[$key]['known_token_usage'] += $total;
            $configuration = $this->usageConfiguration($run);
            $configurationKey = implode('|', [$configuration['model'] ?? 'unknown', $configuration['reasoning_setting'] ?? 'default']);
            $harnesses[$key]['configurations'][$configurationKey] ??= [...$configuration, 'run_count' => 0, 'token_usage' => 0];
            $harnesses[$key]['configurations'][$configurationKey]['run_count']++;
            $harnesses[$key]['configurations'][$configurationKey]['token_usage'] += $total;
        }

        foreach ($harnesses as &$usage) {
            $usage['token_usage'] = $usage['token_usage_run_count'] === $usage['run_count']
                ? $usage['known_token_usage']
                : null;
            $usage['average_tokens_per_run'] = $usage['token_usage_run_count'] === 0 ? null : (int) round($usage['known_token_usage'] / $usage['token_usage_run_count']);
            $usage['configurations'] = array_values($usage['configurations']);
        }
        unset($usage);

        $totalProcessedTokens = array_sum(array_column($harnesses, 'known_token_usage'));
        $tokenUsageRunCount = array_sum(array_column($harnesses, 'token_usage_run_count'));

        return [
            'window' => ['key' => $window, 'label' => match ($window) {
                '24h' => 'Last 24 hours', '7d' => 'Last 7 days', default => 'All time'
            }, 'starts_at' => $startsAt?->toIso8601String()],
            'run_count' => $runs->count(),
            'token_usage_run_count' => $tokenUsageRunCount,
            'total_processed_tokens' => $totalProcessedTokens,
            'average_tokens_per_run' => $tokenUsageRunCount === 0 ? null : (int) round($totalProcessedTokens / $tokenUsageRunCount),
            'legacy_incomplete_run_count' => $legacyIncompleteRunCount,
            'legacy_token_usage' => $legacyTokenUsage,
            'harnesses' => $harnesses,
        ];
    }

    private function usageWindow(Request $request): string
    {
        return in_array($request->query('usage_window'), ['24h', '7d', 'all'], true)
            ? $request->query('usage_window')
            : 'all';
    }

    /** @return array{model: ?string, reasoning_setting: ?string} */
    private function usageConfiguration(AgentRun $run): array
    {
        $snapshot = $run->getAttribute('configuration_snapshot');
        $agent = is_array($snapshot) ? $snapshot['agent'] ?? null : null;

        return [
            'model' => is_array($agent) && is_string($agent['model'] ?? null) ? $agent['model'] : null,
            'reasoning_setting' => is_array($agent) && is_string($agent['reasoning_setting'] ?? null) ? $agent['reasoning_setting'] : null,
        ];
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     role: string,
     *     status: string,
     *     last_heartbeat_at: ?string,
     *     cooldown_ends_at: ?string,
     *     lease_state: string,
     *     activity_mode: 'current'|'recent'|null,
     *     run: ?array{
     *         id: int,
     *         status: string,
     *         attempt_number: ?int,
     *         started_at: ?string,
     *         finished_at: ?string,
     *         failure_reason: ?string,
     *         latest_message: ?string,
     *         configuration: ?array{
     *             harness: ?string,
     *             model: ?string,
     *             reasoning_setting: ?string,
     *             configuration_version: ?int,
     *             source: 'snapshot'|'run'
     *         }
     *     },
     *     task: ?array{
     *         id: int,
     *         key: string,
     *         title: string,
     *         status: string,
     *         started_at: ?string,
     *         return_from_reviewer: bool
     *     }
     * }>
     */
    private function officeWorkers(
        Project $project,
        AgentRunRecorder $runs,
    ): array {
        $workers = $project->workers()
            ->select([
                'id',
                'project_id',
                'role',
                'status',
                'last_heartbeat_at',
                'lease_expires_at',
                'task_completed_at',
            ])
            ->orderBy('role')
            ->with([
                'runs' => fn ($query) => $query
                    ->select([
                        'id',
                        'project_id',
                        'task_id',
                        'agent_worker_id',
                        'role',
                        'harness',
                        'status',
                        'attempt_number',
                        'configuration_snapshot',
                        'context_schema_version',
                        'live_output',
                        'log_path',
                        'started_at',
                        'finished_at',
                    ])
                    ->latest('started_at')
                    ->limit(1)
                    ->with([
                        'task' => fn ($query) => $query
                            ->select([
                                'id',
                                'key',
                                'title',
                                'status',
                                'claimed_at',
                            ])
                            ->with([
                                'reviews' => fn ($reviews) => $reviews
                                    ->select(['id', 'task_id', 'status'])
                                    ->latest('id')
                                    ->limit(1),
                            ]),
                    ]),
            ])
            ->get();

        $officeWorkers = [];

        foreach ($workers as $worker) {
            $run = $worker->runs->first();
            $task = $run?->task;
            $leaseExpiresAt = $this->dateAttribute(
                $worker,
                'lease_expires_at',
            );
            $leaseState = $leaseExpiresAt === null
                    ? 'none'
                    : ($leaseExpiresAt->isFuture()
                    ? 'active'
                    : 'expired');
            $workerStatus = (string) $worker->getAttribute('status');
            $workerRole = AgentRole::from((string) $worker->getRawOriginal('role'));
            $taskCompletedAt = $this->dateAttribute($worker, 'task_completed_at');
            $cooldownEndsAt = in_array($workerRole, [
                AgentRole::Coder,
                AgentRole::Reviewer,
            ], true)
                && $taskCompletedAt !== null
                ? $taskCompletedAt->addSeconds(
                    max(0, (int) config('aios.worker_task_cooldown_seconds')),
                )
                : null;
            $isCurrentActivity = $run !== null
                && $leaseState !== 'expired'
                && in_array(
                    $workerStatus,
                    ['working', 'recovering'],
                    true,
                )
                && $run->getRawOriginal('status') === 'running';
            $activityMode = $run === null
                ? null
                : ($isCurrentActivity ? 'current' : 'recent');
            $agentMessages = $run !== null && $activityMode === 'current'
                ? $runs->agentMessages($run)
                : [];
            $latestAgentMessage = $agentMessages === []
                ? null
                : $agentMessages[count($agentMessages) - 1];

            $officeWorkers[] = [
                'id' => $worker->id,
                'role' => $worker->getRawOriginal(
                    'role',
                ),
                'status' => $workerStatus,
                'last_heartbeat_at' => $this->serializeDateAttribute(
                    $worker,
                    'last_heartbeat_at',
                ),
                'cooldown_ends_at' => $cooldownEndsAt?->toISOString(),
                'lease_state' => $leaseState,
                'activity_mode' => $activityMode,
                'run' => $run === null
                    ? null
                    : [
                        'id' => $run->id,
                        'status' => $run->getRawOriginal(
                            'status',
                        ),
                        'attempt_number' => $run->attempt_number,
                        'started_at' => $this->serializeDateAttribute(
                            $run,
                            'started_at',
                        ),
                        'finished_at' => $this->serializeDateAttribute(
                            $run,
                            'finished_at',
                        ),
                        'failure_reason' => $run->getRawOriginal(
                            'status',
                        ) === 'failed'
                            ? $runs->failureReason(
                                $run,
                            )
                            : null,
                        'latest_message' => $latestAgentMessage === null
                            ? null
                            : Str::limit($latestAgentMessage, 240),
                        'configuration' => $this->officeRunConfiguration($run),
                    ],
                'task' => $task === null
                    ? null
                    : [
                        'id' => $task->id,
                        'key' => $task->key,
                        'title' => $task->title,
                        'status' => $task->getRawOriginal(
                            'status',
                        ),
                        'started_at' => $this->serializeDateAttribute(
                            $task,
                            'claimed_at',
                        ),
                        'return_from_reviewer' => $task->reviews->first()?->getRawOriginal('status') === 'changes_required',
                    ],
            ];
        }

        return $officeWorkers;
    }

    /**
     * Expose only the safe immutable execution identity needed by the command center.
     * Full default context and Skill content remain on the AgentRun detail evidence.
     *
     * @return array{
     *     harness: ?string,
     *     model: ?string,
     *     reasoning_setting: ?string,
     *     configuration_version: ?int,
     *     source: 'snapshot'|'run'
     * }|null
     */
    private function officeRunConfiguration(AgentRun $run): ?array
    {
        $snapshot = $run->getAttribute('configuration_snapshot');
        $agent = is_array($snapshot)
            ? ($snapshot['agent'] ?? null)
            : null;

        if (is_array($agent)) {
            $snapshotHarness = $agent['harness'] ?? null;
            $model = $agent['model'] ?? null;
            $reasoningSetting = $agent['reasoning_setting'] ?? null;
            $configurationVersion = $agent['configuration_version'] ?? null;
            $runHarness = $run->getRawOriginal('harness');

            return [
                'harness' => is_string($snapshotHarness) && $snapshotHarness !== ''
                    ? $snapshotHarness
                    : (is_string($runHarness) && $runHarness !== ''
                        ? $runHarness
                        : null),
                'model' => is_string($model) && $model !== ''
                    ? $model
                    : null,
                'reasoning_setting' => is_string($reasoningSetting) && $reasoningSetting !== ''
                    ? $reasoningSetting
                    : null,
                'configuration_version' => is_int($configurationVersion)
                    ? $configurationVersion
                    : null,
                'source' => 'snapshot',
            ];
        }

        $runHarness = $run->getRawOriginal('harness');

        if (! is_string($runHarness) || $runHarness === '') {
            return null;
        }

        return [
            'harness' => $runHarness,
            'model' => null,
            'reasoning_setting' => null,
            'configuration_version' => null,
            'source' => 'run',
        ];
    }

    /**
     * @param array<int, array{
     *     id: int,
     *     role: string,
     *     status: string,
     *     last_heartbeat_at: ?string,
     *     lease_state: string,
     *     activity_mode: 'current'|'recent'|null,
     *     run: ?array{
     *         id: int,
     *         status: string,
     *         attempt_number: ?int,
     *         started_at: ?string,
     *         finished_at: ?string,
     *         failure_reason: ?string,
     *         latest_message: ?string,
     *         configuration: ?array{
     *             harness: ?string,
     *             model: ?string,
     *             reasoning_setting: ?string,
     *             configuration_version: ?int,
     *             source: 'snapshot'|'run'
     *         }
     *     },
     *     task: ?array{
     *         id: int,
     *         key: string,
     *         title: string,
     *         status: string,
     *         return_from_reviewer: bool
     *     }
     * }> $workers
     * @return array{
     *     mode: 'current'|'recent',
     *     worker_id: int,
     *     role: string,
     *     run_id: int,
     *     task: ?array{id: int, key: string, title: string, status: string, return_from_reviewer: bool}
     * }|null
     */
    private function officeWorkflow(array $workers): ?array
    {
        $coreRoles = [
            AgentRole::ProjectManager->value,
            AgentRole::Coder->value,
            AgentRole::Reviewer->value,
        ];
        $selectedMode = null;
        $selectedWorkerId = null;
        $selectedRole = null;
        $selectedRunId = null;
        $selectedTask = null;
        $selectedStartedAt = '';

        foreach ($workers as $worker) {
            if (! in_array($worker['role'], $coreRoles, true)) {
                continue;
            }

            $run = $worker['run'];
            $mode = $worker['activity_mode'];

            if ($run === null || $mode === null) {
                continue;
            }

            if ($mode === 'recent' && $worker['task'] === null) {
                continue;
            }

            $modeRank = $mode === 'current' ? 1 : 0;
            $selectedRank = $selectedMode === 'current' ? 1 : 0;
            $startedAt = $run['started_at'] ?? '';
            $isNewer = $startedAt > $selectedStartedAt
                || ($startedAt === $selectedStartedAt
                    && $run['id'] > ($selectedRunId ?? 0));

            if (
                $selectedMode !== null
                && $modeRank < $selectedRank
            ) {
                continue;
            }

            if (
                $selectedMode !== null
                && $modeRank === $selectedRank
                && ! $isNewer
            ) {
                continue;
            }

            $selectedMode = $mode;
            $selectedWorkerId = $worker['id'];
            $selectedRole = $worker['role'];
            $selectedRunId = $run['id'];
            $selectedTask = $worker['task'];
            $selectedStartedAt = $startedAt;
        }

        if (
            $selectedMode === null
            || $selectedWorkerId === null
            || $selectedRole === null
            || $selectedRunId === null
        ) {
            return null;
        }

        return [
            'mode' => $selectedMode,
            'worker_id' => $selectedWorkerId,
            'role' => $selectedRole,
            'run_id' => $selectedRunId,
            'task' => $selectedTask,
        ];
    }

    /**
     * Resolve one Eloquent date cast through a dynamic attribute boundary with an explicit runtime type.
     */
    private function dateAttribute(
        AgentRun|AgentWorker|ProjectReconciliationRun|Task $model,
        string $attribute,
    ): ?CarbonInterface {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface ? $value : null;
    }

    /**
     * Serialize one cast date attribute for the Inertia project payload.
     */
    private function serializeDateAttribute(
        AgentRun|AgentWorker|ProjectReconciliationRun|Task $model,
        string $attribute,
    ): ?string {
        return $this->dateAttribute($model, $attribute)?->toISOString();
    }

    public function destroy(
        Project $project,
        DeleteProject $deleteProject,
    ): RedirectResponse {
        $deleteProject->handle($project);

        return to_route('projects.index');
    }

    public function updateStatus(
        UpdateProjectStatusRequest $request,
        Project $project,
        SetProjectStatus $setProjectStatus,
    ): RedirectResponse {
        $setProjectStatus->handle(
            $project,
            ProjectStatus::from(
                $request->validated('status'),
            ),
        );

        return to_route('projects.show', $project);
    }

    public function clearTasks(Project $project, ClearProjectTasks $clearProjectTasks): RedirectResponse
    {
        $clearProjectTasks->handle($project);

        return to_route('projects.show', $project);
    }

    public function requeueTask(
        Project $project,
        Task $task,
        RequeueBlockedTask $requeueBlockedTask,
    ): RedirectResponse {
        abort_unless(
            $task->project_id === $project->id,
            404,
        );

        $requeueBlockedTask->handle($task);

        return to_route('projects.show', $project);
    }

    public function skipTask(
        SkipBlockedTaskRequest $request,
        Project $project,
        Task $task,
        SkipBlockedTask $skipBlockedTask,
    ): RedirectResponse {
        abort_unless(
            $task->project_id === $project->id,
            404,
        );

        $skipBlockedTask->handle(
            $task,
            $request->validated('reason'),
        );

        return to_route(
            'projects.tasks.show',
            [$project, $task],
        );
    }

    public function showTask(
        Project $project,
        Task $task,
        AgentRunRecorder $runs,
    ): Response {
        abort_unless(
            $task->project_id === $project->id,
            404,
        );

        $task->load([
            'phase',
            'dependencies:id,key,title,status',
            'dependents:id,key,title,status',
            'attempts' => fn ($query) => $query->latest('number'),
            'reviews' => fn ($query) => $query
                ->latest()
                ->with([
                    'attempt:id,number,commit_sha',
                    'findings',
                ]),
            'runs' => fn ($query) => $query
                ->select([
                    'id',
                    'task_id',
                    'role',
                    'status',
                    'attempt_number',
                    'live_output',
                    'log_path',
                    'exit_code',
                    'started_at',
                    'finished_at',
                ])
                ->latest('started_at'),
            'handoffs' => fn ($query) => $query
                ->where('project_id', $project->id)
                ->whereHas(
                    'sourceRun',
                    fn ($sourceRuns) => $sourceRuns
                        ->where('project_id', $project->id)
                        ->where('task_id', $task->id),
                )
                ->select([
                    'id',
                    'project_id',
                    'task_id',
                    'from_agent_run_id',
                    'from_role',
                    'to_role',
                    'handoff_type',
                    'schema_version',
                    'payload',
                    'content_hash',
                    'status',
                    'created_at',
                    'consumed_at',
                ])
                ->with([
                    'sourceRun' => fn ($sourceRuns) => $sourceRuns
                        ->where('project_id', $project->id)
                        ->where('task_id', $task->id)
                        ->select([
                            'id',
                            'project_id',
                            'task_id',
                            'role',
                            'status',
                            'attempt_number',
                            'started_at',
                            'finished_at',
                        ]),
                ])
                ->oldest('created_at')
                ->oldest('id'),
            'operatorMessages' => fn ($query) => $query
                ->oldest()
                ->with('user:id,name'),
            'auditEvents' => fn ($query) => $query
                ->latest('occurred_at')
                ->limit(30),
        ]);

        $task->runs->each(
            function ($run) use ($runs): void {
                $transcript = $runs->transcript($run);
                $run->setAttribute(
                    'live_output',
                    $transcript,
                );
                $run->setAttribute(
                    'transcript',
                    $transcript,
                );
                $run->setAttribute(
                    'agent_messages',
                    $runs->agentMessages($run),
                );
                $run->makeHidden('log_path');
            },
        );

        $latestEscalation = $task->auditEvents()
            ->where('event_type', 'recovery.escalated')
            ->latest('occurred_at')
            ->first();

        $latestEscalationPayload = $latestEscalation?->getAttribute('payload');
        $recoveryEscalationReason = null;

        if (
            is_array($latestEscalationPayload)
            && is_string($latestEscalationPayload['escalation_reason'] ?? null)
        ) {
            $recoveryEscalationReason =
                $latestEscalationPayload['escalation_reason'];
        }

        return Inertia::render(
            'projects/tasks/show',
            [
                'project' => $project->only([
                    'id',
                    'name',
                    'path',
                ]),
                'task' => $task,
                'recovery_escalation_reason' => $recoveryEscalationReason,
            ],
        );
    }

    public function showAgentRun(
        Project $project,
        AgentRun $run,
        AgentRunRecorder $runs,
    ): Response {
        $run->loadMissing(
            'task:id,key,title',
            'worker:id,role,status,last_heartbeat_at',
        );
        $run->setAttribute(
            'agent_messages',
            $runs->agentMessages($run),
        );
        $run->makeHidden('log_path');

        $isProjectManager =
            $run->getRawOriginal('role')
            === AgentRole::ProjectManager->value;

        return Inertia::render(
            'projects/agent-runs/show',
            [
                'project' => $project->only([
                    'id',
                    'name',
                    'path',
                ]),
                'agent_run' => $run,
                'project_manager_messages' => $isProjectManager
                        ? $project
                            ->projectManagerMessages()
                            ->oldest()
                            ->with('user:id,name')
                            ->get()
                        : [],
            ],
        );
    }

    public function storeProjectManagerMessage(
        StoreProjectManagerMessageRequest $request,
        Project $project,
        RecordProjectManagerMessage $messages,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $messages->handle(
            $project,
            $user,
            $request->validated('body'),
        );

        return back();
    }

    public function storeOperatorMessage(
        StoreTaskOperatorMessageRequest $request,
        Project $project,
        Task $task,
        RecordTaskOperatorMessage $recordTaskOperatorMessage,
    ): RedirectResponse {
        abort_unless(
            $task->project_id === $project->id,
            404,
        );

        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $recordTaskOperatorMessage->handle(
            $task,
            $user,
            AgentRole::from(
                $validated['recipient_role'],
            ),
            $validated['body'],
        );

        return to_route(
            'projects.tasks.show',
            [$project, $task],
        );
    }

    public function storeRoadmap(
        StoreRoadmapRequest $request,
        Project $project,
        StoreRoadmap $storeRoadmap,
    ): RedirectResponse {
        $file = $request->file('roadmap');

        if (! $file instanceof UploadedFile) {
            abort(422, 'A roadmap file is required.');
        }

        $storeRoadmap->handle($project, $file);

        return to_route('projects.show', $project);
    }

    public function requeueRoadmap(
        Project $project,
        Roadmap $roadmap,
        RequeueBlockedRoadmap $requeueBlockedRoadmap,
    ): RedirectResponse {
        abort_unless($roadmap->project_id === $project->id, 404);

        $requeueBlockedRoadmap->handle($roadmap);

        return to_route('projects.show', $project);
    }

    public function requestReconciliation(
        Request $request,
        Project $project,
        RequestProjectReconciliation $reconciliation,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $reconciliation->handle($project, ProjectReconciliationTrigger::Manual, $user);

        return to_route('projects.show', $project);
    }

    /** Persist the explicit operator opt-in/out for governed documentation Task creation. */
    public function updateStewardshipPolicy(UpdateProjectStewardshipPolicyRequest $request, Project $project, AuditLogger $audit): RedirectResponse
    {
        $storedPolicy = $project->getAttribute('stewardship_policy');
        $policy = is_array($storedPolicy) ? $storedPolicy : [];
        $policy['version'] = 1;
        $policy['automatic_task_creation'] = $request->boolean('automatic_task_creation');
        $project->update(['stewardship_policy' => $policy]);
        $audit->record('stewardship.policy_updated', ['automatic_task_creation' => $policy['automatic_task_creation']], $project);

        return to_route('projects.show', $project);
    }
}
