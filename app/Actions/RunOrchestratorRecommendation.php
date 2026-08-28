<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\OrchestrationRecommendation;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\OrchestrationRecommendationType;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarnessResolver;
use App\Services\AgentResolver;
use App\Services\AgentRunRecorder;
use App\Services\AssembledAgentContext;
use App\Services\AuditLogger;
use App\Services\GlobalAgentResolver;
use App\Services\OrchestrationRecommendationSchemaValidator;
use App\Services\OrchestratorContextCapsuleFactory;
use App\Services\StructuredResultParser;
use App\Services\WorkspacePathResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class RunOrchestratorRecommendation
{
    /**
     * Inject the existing AIOS Agent, harness, context, recommendation, persistence, sandbox, and audit boundaries.
     */
    public function __construct(
        private GlobalAgentResolver $globalAgents,
        private AgentResolver $projectAgents,
        private AgentHarnessResolver $harnesses,
        private AgentContextAssembler $contexts,
        private OrchestratorContextCapsuleFactory $capsules,
        private AgentRunRecorder $runs,
        private StructuredResultParser $parser,
        private OrchestrationRecommendationSchemaValidator $recommendationSchemas,
        private CreateOrchestrationRecommendation $recommendations,
        private WorkspacePathResolver $paths,
        private Filesystem $files,
        private AuditLogger $audit,
    ) {}

    /**
     * Execute one fresh workerless Global Orchestrator analysis and persist only a fully validated advisory recommendation.
     */
    public function handle(
        Project $project,
        ?Task $task = null,
        ?RecoveryIncident $recoveryIncident = null,
    ): ?OrchestrationRecommendation {
        try {
            $agent = $this->globalAgents->forRole(
                AgentRole::Orchestrator,
            );
            $harness = $this->harnesses->resolve($agent);
            $capsule = $this->capsules->make(
                $project,
                $task,
                $recoveryIncident,
            );
            $scopedTask = $this->scopedTask(
                $project,
                $task,
                $capsule,
            );
            $taskContext = $this->taskContext($capsule);
            $context = $this->contexts->assemble(
                $agent,
                AgentRole::Orchestrator,
                $taskContext,
            );
            $prompt = $this->prompt(
                $context,
                $capsule,
            );
            $retrievalManifest = $capsule['retrieval_manifest'] ?? null;

            if (! is_array($retrievalManifest)) {
                throw new LogicException(
                    'The Orchestrator evidence capsule is missing its retrieval manifest.',
                );
            }

            /** @var array<string, mixed> $retrievalManifest */
            $run = $this->runs->start(
                $project,
                AgentRole::Orchestrator,
                $prompt,
                task: $scopedTask,
                retrievalManifest: $retrievalManifest,
                agent: $agent,
                context: $context,
            );
        } catch (Throwable $exception) {
            $this->recordExecutionFailure(
                $project,
                $task,
                null,
                'pre_execution',
                $exception->getMessage(),
            );

            return null;
        }

        $sandboxPath = null;

        try {
            [$executionProject, $sandboxPath] = $this->isolatedExecutionProject(
                $project,
            );

            $execution = $harness->execute(
                $executionProject,
                $agent,
                $prompt,
            );
        } catch (Throwable $exception) {
            $failedRun = $this->runs->complete($run, [
                'exit_code' => -1,
                'output' => '',
                'error_output' => 'Orchestrator execution failed safely: '
                    .$exception->getMessage(),
            ]);

            $this->recordExecutionFailure(
                $project,
                $scopedTask,
                $failedRun,
                'harness_exception',
                $exception->getMessage(),
            );

            return null;
        } finally {
            if (is_string($sandboxPath) && $sandboxPath !== '') {
                $this->files->deleteDirectory($sandboxPath);
            }
        }

        $completedRun = $this->runs->complete(
            $run,
            $execution->toArray(),
        );

        if ($execution->exitCode !== 0) {
            $this->recordExecutionFailure(
                $project,
                $scopedTask,
                $completedRun,
                'harness_failure',
                $execution->errorOutput,
            );

            return null;
        }

        $structured = $this->parser->parseAgentMessage(
            $execution->output,
        );

        if (! is_array($structured)) {
            $this->recordRecommendationRejection(
                $project,
                $scopedTask,
                $completedRun,
                'malformed_output',
                'The Orchestrator returned malformed structured output.',
            );

            return null;
        }

        try {
            $envelope = $this->validateEnvelope(
                $structured,
                $capsule,
            );
            $type = OrchestrationRecommendationType::tryFrom(
                $envelope['proposed_action_category'],
            );

            if ($type === null) {
                throw ValidationException::withMessages([
                    'result.proposed_action_category' => 'The Orchestrator returned an unsupported proposed action category.',
                ]);
            }

            $validatedRecommendation = $this->recommendationSchemas->validate(
                $type,
                $envelope['schema_version'],
                $envelope['recommendation'],
            );

            $this->validateProposedConfiguration(
                $project,
                $type,
                $validatedRecommendation,
            );
        } catch (ValidationException|LogicException $exception) {
            $this->recordRecommendationRejection(
                $project,
                $scopedTask,
                $completedRun,
                'validation_rejected',
                $exception->getMessage(),
            );

            return null;
        }

        try {
            return $this->recommendations->handle(
                $completedRun,
                $type,
                $envelope['schema_version'],
                $envelope['confidence'],
                $validatedRecommendation,
                project: $project,
                task: $scopedTask,
                recoveryIncident: $recoveryIncident,
            );
        } catch (ValidationException|LogicException $exception) {
            $this->recordRecommendationRejection(
                $project,
                $scopedTask,
                $completedRun,
                'persistence_validation_rejected',
                $exception->getMessage(),
            );

            return null;
        } catch (Throwable $exception) {
            $this->recordExecutionFailure(
                $project,
                $scopedTask,
                $completedRun,
                'recommendation_persistence',
                $exception->getMessage(),
            );

            return null;
        }
    }

    /**
     * Resolve the incident-linked Task identity already validated by the P5-003 context capsule.
     *
     * @param  array<string, mixed>  $capsule
     */
    private function scopedTask(
        Project $project,
        ?Task $task,
        array $capsule,
    ): ?Task {
        if ($task !== null) {
            return $task;
        }

        $scope = $capsule['scope'] ?? null;

        if (! is_array($scope)) {
            throw new LogicException(
                'The Orchestrator evidence capsule is missing a valid scope.',
            );
        }

        $taskId = $scope['task_id'] ?? null;

        if ($taskId === null) {
            return null;
        }

        if (! is_int($taskId) || $taskId < 1) {
            throw new LogicException(
                'The Orchestrator evidence capsule contains an invalid Task scope.',
            );
        }

        $resolved = Task::query()
            ->where('project_id', $project->id)
            ->whereKey($taskId)
            ->first();

        if ($resolved === null) {
            throw new LogicException(
                'The Orchestrator evidence capsule references an unavailable scoped Task.',
            );
        }

        return $resolved;
    }

    /**
     * Extend the deterministic P5-003 evidence capsule with only the execution contract and current harness capability evidence.
     *
     * @param  array<string, mixed>  $capsule
     * @return array<string, mixed>
     */
    private function taskContext(array $capsule): array
    {
        return [
            'objective' => 'Evaluate the supplied durable AIOS evidence and return exactly one advisory recommendation.',
            'acceptance_criteria' => [
                'Use only the supplied bounded AIOS evidence.',
                'Return exactly one structured recommendation envelope and no prose.',
                'Reference only evidence listed as included in retrieval_manifest.sources.',
                'Recommend only an allowlisted proposed action category.',
                'Do not inspect or modify the managed project repository.',
                'Do not mutate Agent configuration, bindings, workers, Tasks, workflow state, routing, Git, or any other durable state.',
                'Do not return chain-of-thought or hidden reasoning.',
            ],
            'allowed_action_categories' => $this->actionCategoryValues(),
            'supported_harness_capabilities' => $this->harnesses->capabilities(),
            'evidence_reference_manifest' => $this->evidenceReferenceManifest($capsule),
            ...$capsule,
        ];
    }

    /**
     * Keep the small evidence-reference allowlist non-reducible even when the larger retrieval manifest is context-budget trimmed.
     *
     * @param  array<string, mixed>  $capsule
     * @return list<array{family: string, ids: list<int>}>
     */
    private function evidenceReferenceManifest(array $capsule): array
    {
        $manifest = $capsule['retrieval_manifest'] ?? null;
        $sources = is_array($manifest) ? ($manifest['sources'] ?? null) : null;

        if (! is_array($sources)) {
            throw new LogicException(
                'The Orchestrator evidence capsule is missing reproducible source evidence.',
            );
        }

        $references = [];

        foreach ($sources as $source) {
            if (! is_array($source) || ($source['state'] ?? null) !== 'included') {
                continue;
            }

            $family = $source['family'] ?? null;
            $ids = $source['ids'] ?? null;

            if (! is_string($family) || ! is_array($ids)) {
                continue;
            }

            $normalizedIds = array_values(array_filter(
                $ids,
                static fn (mixed $id): bool => is_int($id) && $id > 0,
            ));
            sort($normalizedIds, SORT_NUMERIC);

            $references[] = [
                'family' => $family,
                'ids' => array_values(array_unique($normalizedIds)),
            ];
        }

        return $references;
    }

    /**
     * Build a compact non-authoritative prompt guide while leaving the existing schema validator authoritative.
     *
     * @return array<string, mixed>
     */
    private function recommendationContract(): array
    {
        return [
            OrchestrationRecommendationType::AgentConfiguration->value => [
                'target_role' => 'supported Agent role',
                'changes' => [
                    'harness' => 'optional supported harness or null',
                    'model' => 'optional supported model or null',
                    'reasoning_setting' => 'optional supported reasoning setting or null',
                    'default_context' => 'optional bounded string or null',
                ],
                'reason' => 'non-empty string',
            ],
            OrchestrationRecommendationType::HarnessModel->value => [
                'target_role' => 'supported Agent role',
                'harness' => 'supported harness',
                'model' => 'supported model or null',
                'reason' => 'non-empty string',
            ],
            OrchestrationRecommendationType::ReasoningLevel->value => [
                'target_role' => 'supported Agent role',
                'reasoning_setting' => 'supported reasoning setting or null',
                'reason' => 'non-empty string',
            ],
            OrchestrationRecommendationType::RetryStrategy->value => [
                'strategy' => 'bounded string',
                'max_attempts' => 'integer 1..10',
                'backoff_seconds' => 'optional integer 0..3600',
                'reason' => 'non-empty string',
            ],
            OrchestrationRecommendationType::ContextStrategy->value => [
                'strategy' => 'bounded string',
                'include_sources' => 'optional list of bounded strings',
                'exclude_sources' => 'optional list of bounded strings',
                'reason' => 'non-empty string',
            ],
            OrchestrationRecommendationType::TaskDecomposition->value => [
                'summary' => 'non-empty string',
                'tasks' => [
                    [
                        'title' => 'non-empty string',
                        'objective' => 'non-empty string',
                    ],
                ],
                'reason' => 'non-empty string',
            ],
            OrchestrationRecommendationType::RecoveryDirection->value => [
                'direction' => 'non-empty string',
                'reason' => 'non-empty string',
            ],
            OrchestrationRecommendationType::WorkflowImprovement->value => [
                'summary' => 'non-empty string',
                'proposed_change' => 'non-empty string',
                'reason' => 'non-empty string',
            ],
        ];
    }

    /**
     * Build the provider prompt with the existing AIOS assembled-context marker required by Context Budget enforcement.
     *
     * @param  array<string, mixed>  $capsule
     */
    private function prompt(
        AssembledAgentContext $context,
        array $capsule,
    ): string {
        $scope = $capsule['scope'] ?? null;

        if (! is_array($scope)) {
            throw new LogicException(
                'The Orchestrator evidence capsule is missing a valid scope.',
            );
        }

        $envelope = [
            'schema_version' => OrchestrationRecommendationSchemaValidator::SchemaVersion,
            'proposed_action_category' => $this->actionCategoryValues(),
            'confidence' => 'number from 0 through 1',
            'scope' => $scope,
            'evidence_references' => [
                [
                    'family' => 'family from evidence_reference_manifest',
                    'ids' => 'referenced durable ids from that family',
                ],
            ],
            'recommendation' => 'payload matching the selected proposed_action_category',
        ];

        return implode("\n", [
            'Global Orchestrator advisory execution.',
            'Use only the supplied AIOS context. Do not inspect the filesystem or managed repository.',
            'Do not mutate Agent configuration, Agent bindings, workers, Tasks, workflow state, routing, Git, or any durable application state.',
            'This result is advisory only and cannot apply itself.',
            'Return exactly one JSON object and no prose.',
            'Do not return chain-of-thought or hidden reasoning.',
            'Every evidence reference must match a family and durable id from task_context.evidence_reference_manifest.',
            'Required execution envelope: '
                .json_encode(
                    $envelope,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ),
            'Recommendation payload guidance, with AIOS schema validation remaining authoritative: '
                .json_encode(
                    $this->recommendationContract(),
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ),
            '',
            'AIOS assembled context:',
            json_encode(
                $context->toArray(),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        ]);
    }

    /**
     * Validate and normalize the outer Orchestrator execution envelope before recommendation persistence.
     *
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $capsule
     * @return array{
     *     schema_version: int,
     *     proposed_action_category: string,
     *     confidence: int|float|string,
     *     scope: array{project_id: int, task_id: int|null, recovery_incident_id: int|null},
     *     evidence_references: list<array{family: string, ids: list<int>}>,
     *     recommendation: array<string, mixed>
     * }
     */
    private function validateEnvelope(
        array $structured,
        array $capsule,
    ): array {
        $validated = Validator::make(
            ['result' => $structured],
            [
                'result' => [
                    'required',
                    'array:schema_version,proposed_action_category,confidence,scope,evidence_references,recommendation',
                ],
                'result.schema_version' => [
                    'required',
                    'integer',
                    Rule::in([OrchestrationRecommendationSchemaValidator::SchemaVersion]),
                ],
                'result.proposed_action_category' => [
                    'required',
                    'string',
                    Rule::in($this->actionCategoryValues()),
                ],
                'result.confidence' => [
                    'required',
                    'numeric',
                    'between:0,1',
                ],
                'result.scope' => [
                    'required',
                    'array:project_id,task_id,recovery_incident_id',
                ],
                'result.scope.project_id' => [
                    'required',
                    'integer',
                    'min:1',
                ],
                'result.scope.task_id' => [
                    'present',
                    'nullable',
                    'integer',
                    'min:1',
                ],
                'result.scope.recovery_incident_id' => [
                    'present',
                    'nullable',
                    'integer',
                    'min:1',
                ],
                'result.evidence_references' => [
                    'required',
                    'array',
                    'min:1',
                    'max:20',
                ],
                'result.evidence_references.*' => [
                    'required',
                    'array:family,ids',
                ],
                'result.evidence_references.*.family' => [
                    'required',
                    'string',
                    'max:120',
                ],
                'result.evidence_references.*.ids' => [
                    'required',
                    'array',
                    'max:100',
                ],
                'result.evidence_references.*.ids.*' => [
                    'integer',
                    'min:1',
                    'distinct',
                ],
                'result.recommendation' => [
                    'required',
                    'array',
                ],
            ],
        )->validate();

        $result = $validated['result'] ?? null;

        if (! is_array($result)) {
            throw ValidationException::withMessages([
                'result' => 'A structured Orchestrator result is required.',
            ]);
        }

        $schemaVersion = $result['schema_version'] ?? null;
        $category = $result['proposed_action_category'] ?? null;
        $confidence = $result['confidence'] ?? null;
        $scope = $result['scope'] ?? null;
        $references = $result['evidence_references'] ?? null;
        $recommendation = $result['recommendation'] ?? null;

        if (! is_int($schemaVersion)
            || ! is_string($category)
            || (! is_int($confidence) && ! is_float($confidence) && ! is_string($confidence))
            || ! is_array($scope)
            || ! is_array($references)
            || ! is_array($recommendation)) {
            throw ValidationException::withMessages([
                'result' => 'The Orchestrator result could not be normalized safely.',
            ]);
        }

        $projectId = $scope['project_id'] ?? null;
        $taskId = $scope['task_id'] ?? null;
        $recoveryIncidentId = $scope['recovery_incident_id'] ?? null;

        if (! is_int($projectId)
            || ($taskId !== null && ! is_int($taskId))
            || ($recoveryIncidentId !== null && ! is_int($recoveryIncidentId))) {
            throw ValidationException::withMessages([
                'result.scope' => 'The Orchestrator result contains an invalid scope.',
            ]);
        }

        /** @var array<string, mixed> $recommendation */
        $normalizedReferences = [];

        foreach ($references as $reference) {
            if (! is_array($reference)) {
                throw ValidationException::withMessages([
                    'result.evidence_references' => 'Every evidence reference must be an object.',
                ]);
            }

            $family = $reference['family'] ?? null;
            $ids = $reference['ids'] ?? null;

            if (! is_string($family) || ! is_array($ids)) {
                throw ValidationException::withMessages([
                    'result.evidence_references' => 'Every evidence reference must contain a family and ids list.',
                ]);
            }

            $normalizedIds = [];

            foreach ($ids as $id) {
                if (! is_int($id)) {
                    throw ValidationException::withMessages([
                        'result.evidence_references' => 'Evidence reference ids must be integers.',
                    ]);
                }

                $normalizedIds[] = $id;
            }

            sort($normalizedIds, SORT_NUMERIC);

            $normalizedReferences[] = [
                'family' => $family,
                'ids' => $normalizedIds,
            ];
        }

        $normalizedScope = [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'recovery_incident_id' => $recoveryIncidentId,
        ];

        $this->validateScope(
            $normalizedScope,
            $capsule,
        );
        $this->validateEvidenceReferences(
            $normalizedReferences,
            $capsule,
        );

        return [
            'schema_version' => $schemaVersion,
            'proposed_action_category' => $category,
            'confidence' => $confidence,
            'scope' => $normalizedScope,
            'evidence_references' => $normalizedReferences,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Require the model-returned scope to exactly match the AIOS-generated P5-003 scope.
     *
     * @param  array{project_id: int, task_id: int|null, recovery_incident_id: int|null}  $scope
     * @param  array<string, mixed>  $capsule
     */
    private function validateScope(
        array $scope,
        array $capsule,
    ): void {
        $expected = $capsule['scope'] ?? null;

        if (! is_array($expected)) {
            throw new LogicException(
                'The Orchestrator evidence capsule is missing a valid scope.',
            );
        }

        $projectId = $expected['project_id'] ?? null;
        $taskId = $expected['task_id'] ?? null;
        $recoveryIncidentId = $expected['recovery_incident_id'] ?? null;

        if (! is_int($projectId)
            || ($taskId !== null && ! is_int($taskId))
            || ($recoveryIncidentId !== null && ! is_int($recoveryIncidentId))) {
            throw new LogicException(
                'The Orchestrator evidence capsule contains an invalid scope.',
            );
        }

        if ($scope !== [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'recovery_incident_id' => $recoveryIncidentId,
        ]) {
            throw ValidationException::withMessages([
                'result.scope' => 'The Orchestrator recommendation scope must exactly match the AIOS-supplied evidence scope.',
            ]);
        }
    }

    /**
     * Require every model-returned evidence reference to resolve to an included P5-003 manifest source and durable id.
     *
     * @param  list<array{family: string, ids: list<int>}>  $references
     * @param  array<string, mixed>  $capsule
     */
    private function validateEvidenceReferences(
        array $references,
        array $capsule,
    ): void {
        $manifest = $capsule['retrieval_manifest'] ?? null;
        $sources = is_array($manifest)
            ? ($manifest['sources'] ?? null)
            : null;

        if (! is_array($sources)) {
            throw new LogicException(
                'The Orchestrator evidence capsule is missing reproducible source evidence.',
            );
        }

        $included = [];

        foreach ($sources as $source) {
            if (! is_array($source)
                || ($source['state'] ?? null) !== 'included') {
                continue;
            }

            $family = $source['family'] ?? null;
            $ids = $source['ids'] ?? null;

            if (! is_string($family) || ! is_array($ids)) {
                continue;
            }

            $sourceIds = [];

            foreach ($ids as $id) {
                if (is_int($id) && $id > 0) {
                    $sourceIds[] = $id;
                }
            }

            sort($sourceIds, SORT_NUMERIC);
            $included[$family] = array_values(array_unique($sourceIds));
        }

        $seen = [];

        foreach ($references as $reference) {
            $family = $reference['family'];
            $ids = $reference['ids'];

            if (isset($seen[$family])) {
                throw ValidationException::withMessages([
                    'result.evidence_references' => "Evidence family [{$family}] may be referenced only once.",
                ]);
            }

            $seen[$family] = true;

            if (! array_key_exists($family, $included)) {
                throw ValidationException::withMessages([
                    'result.evidence_references' => "Evidence family [{$family}] was not included in the AIOS evidence capsule.",
                ]);
            }

            $allowedIds = $included[$family];

            if ($allowedIds !== [] && $ids === []) {
                throw ValidationException::withMessages([
                    'result.evidence_references' => "Evidence family [{$family}] requires at least one durable source id.",
                ]);
            }

            foreach ($ids as $id) {
                if (! in_array($id, $allowedIds, true)) {
                    throw ValidationException::withMessages([
                        'result.evidence_references' => "Evidence id [{$id}] is not valid for family [{$family}].",
                    ]);
                }
            }
        }
    }

    /**
     * Validate a proposed target Agent/harness/model/reasoning combination entirely in memory through the existing resolver.
     *
     * @param  array<string, mixed>  $recommendation
     */
    private function validateProposedConfiguration(
        Project $project,
        OrchestrationRecommendationType $type,
        array $recommendation,
    ): void {
        if (! in_array($type, [
            OrchestrationRecommendationType::AgentConfiguration,
            OrchestrationRecommendationType::HarnessModel,
            OrchestrationRecommendationType::ReasoningLevel,
        ], true)) {
            return;
        }

        $targetRoleValue = $recommendation['target_role'] ?? null;

        if (! is_string($targetRoleValue)) {
            throw new LogicException(
                'A configuration recommendation is missing its target Agent role.',
            );
        }

        $targetRole = AgentRole::tryFrom($targetRoleValue);

        if ($targetRole === null) {
            throw new LogicException(
                "Unsupported target Agent role [{$targetRoleValue}].",
            );
        }

        $candidate = clone $this->targetAgent(
            $project,
            $targetRole,
        );
        $attributes = [];

        if ($type === OrchestrationRecommendationType::AgentConfiguration) {
            $changes = $recommendation['changes'] ?? null;

            if (! is_array($changes)) {
                throw new LogicException(
                    'The Agent configuration recommendation is missing its changes object.',
                );
            }

            foreach ([
                'harness',
                'model',
                'reasoning_setting',
                'default_context',
            ] as $attribute) {
                if (array_key_exists($attribute, $changes)) {
                    $attributes[$attribute] = $changes[$attribute];
                }
            }
        }

        if ($type === OrchestrationRecommendationType::HarnessModel) {
            $attributes['harness'] = $recommendation['harness'] ?? null;
            $attributes['model'] = $recommendation['model'] ?? null;
        }

        if ($type === OrchestrationRecommendationType::ReasoningLevel) {
            $attributes['reasoning_setting'] = $recommendation['reasoning_setting'] ?? null;
        }

        $candidate->forceFill($attributes);
        $candidate->syncOriginal();

        $this->harnesses->resolve($candidate);
    }

    /**
     * Resolve the durable target Agent without allowing a recommendation to invent an Agent identity or binding.
     */
    private function targetAgent(
        Project $project,
        AgentRole $role,
    ): Agent {
        return match ($role) {
            AgentRole::ProjectManager,
            AgentRole::Coder,
            AgentRole::Reviewer => $this->projectAgents->forRole(
                $project,
                $role,
            ),
            AgentRole::KnowledgeArchitect,
            AgentRole::Orchestrator,
            AgentRole::RecoveryEngineer => $this->globalAgents->forRole(
                $role,
            ),
        };
    }

    /**
     * Return every allowlisted recommendation category from the existing domain enum.
     *
     * @return list<string>
     */
    private function actionCategoryValues(): array
    {
        return array_map(
            static fn (OrchestrationRecommendationType $type): string => $type->value,
            OrchestrationRecommendationType::cases(),
        );
    }

    /**
     * Create a disposable empty execution workspace so the Orchestrator cannot inspect or modify the managed project repository.
     *
     * @return array{0: Project, 1: string}
     */
    private function isolatedExecutionProject(Project $project): array
    {
        $path = $this->paths->resolve(
            '.aios-orchestrator/'.Str::uuid(),
        );

        $this->files->ensureDirectoryExists($path);

        $executionProject = clone $project;
        $executionProject->setAttribute('path', $path);

        return [$executionProject, $path];
    }

    /**
     * Record operational execution or persistence failure without creating a recommendation or mutating workflow state.
     */
    private function recordExecutionFailure(
        Project $project,
        ?Task $task,
        ?AgentRun $run,
        string $stage,
        string $reason,
    ): void {
        $this->audit->record(
            'orchestrator.execution_failed',
            [
                'agent_run_id' => $run?->id,
                'stage' => $stage,
                'reason' => $reason,
            ],
            $project,
            $task,
        );
    }

    /**
     * Record a successful harness execution whose model output failed deterministic recommendation validation.
     */
    private function recordRecommendationRejection(
        Project $project,
        ?Task $task,
        AgentRun $run,
        string $failureType,
        string $reason,
    ): void {
        $this->audit->record(
            'orchestrator.recommendation_rejected',
            [
                'agent_run_id' => $run->id,
                'failure_type' => $failureType,
                'reason' => $reason,
            ],
            $project,
            $task,
        );
    }
}
