<?php

namespace App\Http\Controllers;

use App\Actions\SetOrchestrationRecommendationStatus;
use App\AgentRole;
use App\Http\Requests\UpdateOrchestrationRecommendationStatusRequest;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\OrchestrationRecommendation;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\Models\User;
use App\OrchestrationRecommendationStatus;
use App\OrchestrationRecommendationType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class OrchestratorRecommendationController extends Controller
{
    private const int PerPage = 20;

    /**
     * Cache target Agent resolution while serializing one recommendation page.
     *
     * @var array<string, Agent|null>
     */
    private array $targetAgentCache = [];

    /**
     * Render the operator-facing advisory Orchestrator recommendation command center.
     */
    public function index(): Response
    {
        $recommendations = OrchestrationRecommendation::query()
            ->with([
                'project:id,name,path',
                'task:id,project_id,key,title,status',
                'recoveryIncident:id,project_id,task_id,failure_type,status',
                'statusChangedBy:id,name',
                'agentRun:id,project_id,task_id,agent_id,role,harness,status,prompt_hash,result,configuration_snapshot,context_schema_version,context_budget_snapshot,context_budget_schema_version,token_usage,started_at,finished_at',
                'agentRun.agent:id,name,role',
            ])
            ->orderByRaw(
                "CASE status WHEN 'active' THEN 0 WHEN 'dismissed' THEN 1 WHEN 'superseded' THEN 2 ELSE 3 END",
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PerPage)
            ->withQueryString();

        $recommendations->through(
            fn (OrchestrationRecommendation $recommendation): array => $this->serializeRecommendation(
                $recommendation,
            ),
        );

        return Inertia::render('orchestrator/recommendations/index', [
            'recommendations' => $recommendations,
            'summary' => [
                'total' => OrchestrationRecommendation::query()->count(),
                'active' => OrchestrationRecommendation::query()
                    ->where(
                        'status',
                        OrchestrationRecommendationStatus::Active->value,
                    )
                    ->count(),
                'dismissed' => OrchestrationRecommendation::query()
                    ->where(
                        'status',
                        OrchestrationRecommendationStatus::Dismissed->value,
                    )
                    ->count(),
                'superseded' => OrchestrationRecommendation::query()
                    ->where(
                        'status',
                        OrchestrationRecommendationStatus::Superseded->value,
                    )
                    ->count(),
            ],
        ]);
    }

    /**
     * Persist one explicit operator lifecycle decision through the AIOS-owned Action.
     */
    public function updateStatus(
        UpdateOrchestrationRecommendationStatusRequest $request,
        OrchestrationRecommendation $recommendation,
        SetOrchestrationRecommendationStatus $setStatus,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $setStatus->handle(
                $recommendation,
                $user,
                OrchestrationRecommendationStatus::from(
                    $request->string('status')->toString(),
                ),
            );
        } catch (LogicException $exception) {
            return back()->withErrors([
                'status' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Serialize one recommendation without exposing raw provider output, log paths, or live output.
     *
     * @return array<string, mixed>
     */
    private function serializeRecommendation(
        OrchestrationRecommendation $recommendation,
    ): array {
        $projectRelation = $recommendation->getRelation('project');
        $taskRelation = $recommendation->getRelation('task');
        $incidentRelation = $recommendation->getRelation('recoveryIncident');
        $runRelation = $recommendation->getRelation('agentRun');
        $statusChangedBy = $recommendation->getRelation('statusChangedBy');

        $project = $projectRelation instanceof Project
            ? $projectRelation
            : null;
        $task = $taskRelation instanceof Task
            ? $taskRelation
            : null;
        $incident = $incidentRelation instanceof RecoveryIncident
            ? $incidentRelation
            : null;

        if (! $runRelation instanceof AgentRun) {
            throw new LogicException(
                'An orchestration recommendation requires its source AgentRun.',
            );
        }

        $recommendationType = $recommendation->getAttribute(
            'recommendation_type',
        );

        if (! $recommendationType instanceof OrchestrationRecommendationType) {
            throw new LogicException(
                'An orchestration recommendation requires a supported recommendation type.',
            );
        }

        $payload = $recommendation->getAttribute(
            'structured_recommendation',
        );

        if (! is_array($payload)) {
            throw new LogicException(
                'An orchestration recommendation requires a structured payload.',
            );
        }

        $targetRole = $this->targetRole($payload);
        $targetAgent = $targetRole === null
            ? null
            : $this->resolveTargetAgent($project, $targetRole);

        return [
            'id' => $recommendation->id,
            'advisory' => true,
            'recommendation_type' => $recommendationType->value,
            'schema_version' => $recommendation->schema_version,
            'confidence' => $recommendation->confidence,
            'status' => (string) $recommendation->getRawOriginal('status'),
            'created_at' => $this->dateAttribute(
                $recommendation,
                'created_at',
            ),
            'status_changed_at' => $this->dateAttribute(
                $recommendation,
                'status_changed_at',
            ),
            'status_changed_by' => $statusChangedBy instanceof User
                ? [
                    'id' => $statusChangedBy->id,
                    'name' => $statusChangedBy->name,
                ]
                : null,
            'project' => $project === null
                ? null
                : [
                    'id' => $project->id,
                    'name' => $project->name,
                    'path' => $project->path,
                ],
            'task' => $task === null
                ? null
                : [
                    'id' => $task->id,
                    'key' => $task->key,
                    'title' => $task->title,
                    'status' => (string) $task->getRawOriginal('status'),
                ],
            'recovery_incident' => $incident === null
                ? null
                : [
                    'id' => $incident->id,
                    'failure_type' => $incident->failure_type,
                    'status' => (string) $incident->getRawOriginal('status'),
                ],
            'recommendation' => $payload,
            'reason' => is_string($payload['reason'] ?? null)
                ? $payload['reason']
                : null,
            'current_configuration' => $targetAgent === null
                ? null
                : $this->currentConfiguration($targetAgent),
            'suggested_configuration' => $this->suggestedConfiguration(
                $recommendationType,
                $payload,
            ),
            'evaluated_evidence' => [
                'evidence_hash' => $recommendation->evidence_hash,
                'prompt_hash' => $runRelation->prompt_hash,
                'retrieval_manifest' => $this->retrievalManifest($runRelation),
                'context_budget_schema_version' => $runRelation->context_budget_schema_version,
                'context_budget_snapshot' => $runRelation->getAttribute(
                    'context_budget_snapshot',
                ),
            ],
            'agent_run' => [
                'id' => $runRelation->id,
                'role' => (string) $runRelation->getRawOriginal('role'),
                'harness' => $runRelation->getRawOriginal('harness'),
                'status' => (string) $runRelation->getRawOriginal('status'),
                'token_usage' => $runRelation->token_usage,
                'context_schema_version' => $runRelation->context_schema_version,
                'configuration_snapshot' => $runRelation->getAttribute(
                    'configuration_snapshot',
                ),
                'started_at' => $this->dateAttribute(
                    $runRelation,
                    'started_at',
                ),
                'finished_at' => $this->dateAttribute(
                    $runRelation,
                    'finished_at',
                ),
                'agent' => $runRelation->agent === null
                    ? null
                    : [
                        'id' => $runRelation->agent->id,
                        'name' => $runRelation->agent->name,
                        'role' => (string) $runRelation->agent->getRawOriginal(
                            'role',
                        ),
                    ],
                'url' => $runRelation->agent_id === null
                    ? null
                    : route('agents.runs.show', [
                        'agent' => $runRelation->agent_id,
                        'run' => $runRelation->id,
                    ]),
            ],
            'manual_action' => $this->manualAction(
                $recommendationType,
                $project,
                $task,
                $targetRole,
                $targetAgent,
            ),
        ];
    }

    /**
     * Resolve a recommendation payload target role when the recommendation type has one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function targetRole(array $payload): ?AgentRole
    {
        $targetRole = $payload['target_role'] ?? null;

        return is_string($targetRole)
            ? AgentRole::tryFrom($targetRole)
            : null;
    }

    /**
     * Resolve the currently authoritative Agent binding for an advisory target role.
     */
    private function resolveTargetAgent(
        ?Project $project,
        AgentRole $role,
    ): ?Agent {
        $projectId = $project === null ? 0 : $project->id;

        $cacheKey = $this->isProjectRole($role)
            ? 'project:'.$projectId.':'.$role->value
            : 'global:'.$role->value;

        if (array_key_exists($cacheKey, $this->targetAgentCache)) {
            return $this->targetAgentCache[$cacheKey];
        }

        if ($this->isProjectRole($role)) {
            if ($project === null) {
                return $this->targetAgentCache[$cacheKey] = null;
            }

            $worker = AgentWorker::query()
                ->with('agent')
                ->where('project_id', $project->id)
                ->where('role', $role->value)
                ->first();

            return $this->targetAgentCache[$cacheKey] =
                $worker?->agent instanceof Agent
                    ? $worker->agent
                    : null;
        }

        return $this->targetAgentCache[$cacheKey] = Agent::query()
            ->whereNull('project_id')
            ->where('role', $role->value)
            ->first();
    }

    /**
     * Determine whether a role belongs to the project-scoped worker model.
     */
    private function isProjectRole(AgentRole $role): bool
    {
        return in_array(
            $role,
            [
                AgentRole::ProjectManager,
                AgentRole::Coder,
                AgentRole::Reviewer,
            ],
            true,
        );
    }

    /**
     * Serialize current mutable Agent configuration separately from immutable source-run evidence.
     *
     * @return array<string, mixed>
     */
    private function currentConfiguration(Agent $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'scope' => $agent->project_id === null ? 'global' : 'project',
            'role' => (string) $agent->getRawOriginal('role'),
            'harness' => (string) $agent->getRawOriginal('harness'),
            'model' => $agent->model,
            'reasoning_setting' => $agent->reasoning_setting,
            'default_context' => $agent->default_context,
            'configuration_version' => $agent->configuration_version,
            'enabled' => (bool) $agent->enabled,
        ];
    }

    /**
     * Extract only configuration changes from recommendation types that propose configuration.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function suggestedConfiguration(
        OrchestrationRecommendationType $type,
        array $payload,
    ): ?array {
        return match ($type) {
            OrchestrationRecommendationType::AgentConfiguration => is_array(
                $payload['changes'] ?? null,
            )
                ? $payload['changes']
                : null,

            OrchestrationRecommendationType::HarnessModel => [
                'harness' => $payload['harness'] ?? null,
                'model' => $payload['model'] ?? null,
            ],

            OrchestrationRecommendationType::ReasoningLevel => [
                'reasoning_setting' => $payload['reasoning_setting'] ?? null,
            ],

            default => null,
        };
    }

    /**
     * Extract the bounded retrieval manifest persisted on the source AgentRun.
     *
     * @return array<string, mixed>|null
     */
    private function retrievalManifest(AgentRun $run): ?array
    {
        $result = $run->getAttribute('result');

        if (! is_array($result)) {
            return null;
        }

        $manifest = $result['retrieval_manifest'] ?? null;

        return is_array($manifest)
            ? $manifest
            : null;
    }

    /**
     * Build a navigation-only manual action that never performs the recommendation automatically.
     *
     * @return array{label: string, url: string}|null
     */
    private function manualAction(
        OrchestrationRecommendationType $type,
        ?Project $project,
        ?Task $task,
        ?AgentRole $targetRole,
        ?Agent $targetAgent,
    ): ?array {
        if (
            in_array(
                $type,
                [
                    OrchestrationRecommendationType::AgentConfiguration,
                    OrchestrationRecommendationType::HarnessModel,
                    OrchestrationRecommendationType::ReasoningLevel,
                ],
                true,
            )
            && $targetRole !== null
        ) {
            if ($targetAgent !== null && $targetAgent->project_id === null) {
                return [
                    'label' => 'Open Agent configuration',
                    'url' => route('agents.show', $targetAgent),
                ];
            }

            if ($project !== null && $this->isProjectRole($targetRole)) {
                return [
                    'label' => 'Open Agent configuration',
                    'url' => route('projects.show', [
                        'project' => $project,
                        'tab' => 'agents',
                    ]),
                ];
            }
        }

        if ($project !== null && $task !== null) {
            return [
                'label' => 'Open scoped Task',
                'url' => route('projects.tasks.show', [
                    'project' => $project,
                    'task' => $task,
                ]),
            ];
        }

        if ($project !== null) {
            return [
                'label' => 'Open Project',
                'url' => route('projects.show', $project),
            ];
        }

        return null;
    }

    /**
     * Serialize an Eloquent date attribute to an ISO-8601 value for Inertia.
     */
    private function dateAttribute(
        Model $model,
        string $attribute,
    ): ?string {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface
            ? $value->toIso8601String()
            : null;
    }
}
