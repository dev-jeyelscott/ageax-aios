<?php

namespace App\Services;

use App\Actions\RecordKnowledgeImprovementAdvisory;
use App\AgentRole;
use App\AgentRunStatus;
use App\KnowledgeImprovementCandidateStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class KnowledgeArchitectAdvisor
{
    private const int MaxCandidatesPerProject = 20;

    /** @var array<string, string> */
    private const array SemanticFailureTriggers = [
        'knowledge_gap:content_hash_drift' => 'potential_documentation_contradiction',
        'knowledge_gap:superseded_approved_decision' => 'possible_duplicated_architectural_decision',
        'knowledge_gap:obsolete_implementation_note' => 'ambiguous_stale_implementation_note',
    ];

    /**
     * Inject existing global-Agent, context, execution, persistence, path, and audit boundaries.
     */
    public function __construct(
        private GlobalAgentResolver $globalAgents,
        private AgentHarnessResolver $harnesses,
        private AgentContextAssembler $contexts,
        private AgentRunRecorder $runs,
        private StructuredResultParser $parser,
        private RecordKnowledgeImprovementAdvisory $advisories,
        private WorkspacePathResolver $paths,
        private Filesystem $files,
        private AuditLogger $audit,
    ) {}

    /**
     * Analyze pending deterministic candidates whose current evidence requires semantic review.
     */
    public function analyze(Project $project): int
    {
        $scanLimit = max(
            self::MaxCandidatesPerProject,
            (int) config('aios.knowledge_improvement_scan_limit', 500),
        );

        $candidates = KnowledgeImprovementCandidate::query()
            ->whereBelongsTo($project)
            ->where(
                'status',
                KnowledgeImprovementCandidateStatus::Pending->value,
            )
            ->orderBy('id')
            ->limit($scanLimit)
            ->get()
            ->filter(
                fn (KnowledgeImprovementCandidate $candidate): bool => $this->requiresSemanticAnalysis($candidate),
            )
            ->take(self::MaxCandidatesPerProject)
            ->values();

        if ($candidates->isEmpty()) {
            return 0;
        }

        try {
            $agent = $this->globalAgents->forRole(
                AgentRole::KnowledgeArchitect,
            );
        } catch (LogicException $exception) {
            $this->recordUnavailable(
                $project,
                'global_agent_resolution',
                $exception,
            );

            return 0;
        }

        try {
            $harness = $this->harnesses->resolve($agent);
        } catch (LogicException $exception) {
            $this->recordUnavailable(
                $project,
                'harness_resolution',
                $exception,
            );

            return 0;
        }

        $persisted = 0;

        foreach ($candidates as $candidate) {
            if (
                $this->analyzeCandidate(
                    $project,
                    $candidate,
                    $agent,
                    $harness,
                )
            ) {
                $persisted++;
            }
        }

        return $persisted;
    }

    /**
     * Execute one fresh bounded workerless advisory run and persist only validated proposal output.
     */
    private function analyzeCandidate(
        Project $project,
        KnowledgeImprovementCandidate $candidate,
        Agent $agent,
        AgentHarness $harness,
    ): bool {
        $candidate->refresh();

        $sourceEvidenceHash = (string) $candidate->evidence_hash;
        $semanticTriggers = $this->semanticTriggers($candidate);

        if (
            $candidate->getRawOriginal('status')
                !== KnowledgeImprovementCandidateStatus::Pending->value
            || ! $this->isSha256($sourceEvidenceHash)
            || $semanticTriggers === []
            || $this->alreadyAnalyzed(
                $candidate,
                $sourceEvidenceHash,
            )
        ) {
            return false;
        }

        $taskContext = $this->taskContext(
            $project,
            $candidate,
            $semanticTriggers,
        );

        $context = $this->contexts->assemble(
            $agent,
            AgentRole::KnowledgeArchitect,
            $taskContext,
        );

        $prompt = $this->prompt($context);

        $run = $this->runs->start(
            $project,
            AgentRole::KnowledgeArchitect,
            $prompt,
            agent: $agent,
            context: $context,
        );

        $sandboxPath = null;

        try {
            [$executionProject, $sandboxPath]
                = $this->isolatedExecutionProject($project);

            $execution = $harness->execute(
                $executionProject,
                $agent,
                $prompt,
            );

            if ($execution->exitCode !== 0) {
                $this->runs->complete(
                    $run,
                    $execution->toArray(),
                );

                $this->recordExecutionFailure(
                    $project,
                    $candidate,
                    $run,
                    'harness_failure',
                    $execution->errorOutput,
                );

                return false;
            }

            $structured = $this->parser->parseAgentMessage(
                $execution->output,
            );

            if (! is_array($structured)) {
                $this->runs->complete(
                    $run,
                    $this->rejectedExecution(
                        $execution,
                        'Knowledge Architect returned malformed structured output.',
                    ),
                );

                $this->recordExecutionFailure(
                    $project,
                    $candidate,
                    $run,
                    'malformed_output',
                    'Knowledge Architect returned malformed structured output.',
                );

                return false;
            }

            try {
                $validated = $this->advisories->validate(
                    $structured,
                );
            } catch (Throwable $exception) {
                $this->runs->complete(
                    $run,
                    $this->rejectedExecution(
                        $execution,
                        $exception->getMessage(),
                    ),
                );

                $this->recordExecutionFailure(
                    $project,
                    $candidate,
                    $run,
                    'malformed_output',
                    $exception->getMessage(),
                );

                return false;
            }

            $completedRun = $this->runs->complete(
                $run,
                $execution->toArray(),
            );

            $this->advisories->handle(
                $candidate,
                $completedRun,
                $sourceEvidenceHash,
                $validated,
            );

            return true;
        } catch (Throwable $exception) {
            $freshRun = $run->fresh();

            if (
                $freshRun->getRawOriginal('status')
                === AgentRunStatus::Running->value
            ) {
                $this->runs->complete($freshRun, [
                    'exit_code' => 1,
                    'output' => '',
                    'error_output' => 'Knowledge Architect execution failed safely: '
                        .$exception->getMessage(),
                ]);
            }

            $this->recordExecutionFailure(
                $project,
                $candidate,
                $run,
                'execution_exception',
                $exception->getMessage(),
            );

            return false;
        } finally {
            if (
                is_string($sandboxPath)
                && $sandboxPath !== ''
            ) {
                $this->files->deleteDirectory(
                    $sandboxPath,
                );
            }
        }
    }

    /**
     * Build fresh project-scoped context from deterministic candidate and bounded source evidence.
     *
     * @param  list<string>  $semanticTriggers
     * @return array<string, mixed>
     */
    private function taskContext(
        Project $project,
        KnowledgeImprovementCandidate $candidate,
        array $semanticTriggers,
    ): array {
        return [
            'objective' => 'Interpret the supplied deterministic knowledge evidence and return an advisory proposal only.',
            'acceptance_criteria' => [
                'Use only the AIOS-supplied candidate, source-manifest, and bounded source evidence.',
                'Do not inspect or modify the managed project repository or any authoritative knowledge source.',
                'Do not mutate Skills, rules, Agent configuration, Tasks, Tickets, workflow state, Git state, or source manifests.',
                'Do not promote project-specific evidence into global reusable knowledge.',
                'Return exactly one structured JSON object matching the requested advisory schema.',
            ],
            'candidate' => [
                'id' => $candidate->id,
                'fingerprint' => $candidate->fingerprint,
                'source_kind' => $candidate->source_kind,
                'failure_code' => $candidate->failure_code,
                'affected_role' => $candidate->affected_role,
                'affected_area' => $candidate->affected_area,
                'target_type' => (string) $candidate->getRawOriginal(
                    'target_type',
                ),
                'evidence_hash' => $candidate->evidence_hash,
                'evidence_summary' => $candidate->evidence_summary,
                'proposed_change' => $candidate->proposed_change,
                'occurrence_count' => (int) $candidate->occurrence_count,
                'deterministic_evidence' => $this->candidateEvidence(
                    $candidate,
                ),
            ],
            'semantic_triggers' => $semanticTriggers,
            'obsidian_project_knowledge' => $this->boundedSourceEvidence(
                $project,
                $candidate,
            ),
            'cross_project_pattern' => $this->crossProjectPatternEvidence(
                $candidate,
            ),
        ];
    }

    /**
     * Determine whether current candidate evidence requires a new semantic analysis.
     */
    private function requiresSemanticAnalysis(
        KnowledgeImprovementCandidate $candidate,
    ): bool {
        $evidenceHash = (string) $candidate->evidence_hash;

        return $this->isSha256($evidenceHash)
            && $this->semanticTriggers($candidate) !== []
            && ! $this->alreadyAnalyzed(
                $candidate,
                $evidenceHash,
            );
    }

    /**
     * Resolve the semantic trigger labels for one deterministic candidate.
     *
     * @return list<string>
     */
    private function semanticTriggers(
        KnowledgeImprovementCandidate $candidate,
    ): array {
        $triggers = [];

        $failureTrigger =
            self::SemanticFailureTriggers[
                $candidate->failure_code
            ] ?? null;

        if (is_string($failureTrigger)) {
            $triggers[] = $failureTrigger;
        }

        if ($this->otherMatchingProjectCount($candidate) > 0) {
            $triggers[] = 'cross_project_recurring_pattern';
        }

        sort($triggers, SORT_STRING);

        return array_values(array_unique($triggers));
    }

    /**
     * Check whether current deterministic evidence already has a persisted advisory.
     */
    private function alreadyAnalyzed(
        KnowledgeImprovementCandidate $candidate,
        string $evidenceHash,
    ): bool {
        return is_string(
            $candidate->knowledge_architect_evidence_hash,
        ) && hash_equals(
            $candidate->knowledge_architect_evidence_hash,
            $evidenceHash,
        );
    }

    /**
     * Normalize the candidate's cast evidence into a static-analysis-safe list.
     *
     * @return list<array<string, mixed>>
     */
    private function candidateEvidence(
        KnowledgeImprovementCandidate $candidate,
    ): array {
        $value = $candidate->getAttribute('evidence');

        if (! is_array($value)) {
            return [];
        }

        $evidence = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $evidence[] = $item;
            }
        }

        return $evidence;
    }

    /**
     * Read only manifested project-local references named by deterministic evidence within existing context caps.
     *
     * @return array{
     *     sources: list<array<string, mixed>>,
     *     manifest_history: list<array<string, mixed>>
     * }
     */
    private function boundedSourceEvidence(
        Project $project,
        KnowledgeImprovementCandidate $candidate,
    ): array {
        $references = $this->candidateSourceReferences(
            $candidate,
        );

        $manifestHistory = $this->manifestHistory(
            $project,
            $candidate,
        );

        $maxNotes = max(
            0,
            (int) config(
                'aios.obsidian_context_max_notes',
                4,
            ),
        );

        $maxPerNote = max(
            0,
            (int) config(
                'aios.obsidian_context_max_note_characters',
                2000,
            ),
        );

        $remaining = max(
            0,
            (int) config(
                'aios.obsidian_context_max_characters',
                2000,
            ),
        );

        if (
            $references === []
            || $maxNotes === 0
            || $maxPerNote === 0
            || $remaining === 0
        ) {
            return [
                'sources' => [],
                'manifest_history' => $manifestHistory,
            ];
        }

        $manifests = KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->where('source_type', 'obsidian')
            ->whereNull('superseded_at')
            ->whereIn('source_reference', $references)
            ->orderBy('source_reference')
            ->limit($maxNotes)
            ->get();

        $sources = [];

        foreach ($manifests as $manifest) {
            if ($remaining <= 0) {
                break;
            }

            $content = $this->readProjectSource(
                $project,
                (string) $manifest->source_reference,
                min($maxPerNote, $remaining),
            );

            if ($content === null) {
                continue;
            }

            $remaining -= mb_strlen($content);

            $sources[] = [
                'manifest_id' => $manifest->id,
                'source_reference' => $manifest->source_reference,
                'content_hash' => $manifest->content_hash,
                'last_verified_at' => $this->serializeDateAttribute(
                    $manifest,
                    'last_verified_at',
                ),
                'content' => $content,
            ];
        }

        return [
            'sources' => $sources,
            'manifest_history' => $manifestHistory,
        ];
    }

    /**
     * Extract unique project-local source references from deterministic candidate evidence.
     *
     * @return list<string>
     */
    private function candidateSourceReferences(
        KnowledgeImprovementCandidate $candidate,
    ): array {
        $references = [];

        foreach ($this->candidateEvidence($candidate) as $item) {
            foreach (
                ['source_reference', 'target_reference'] as $key
            ) {
                $reference = $item[$key] ?? null;

                if (
                    is_string($reference)
                    && $reference !== ''
                ) {
                    $references[] = $reference;
                }
            }
        }

        $references = array_values(
            array_unique($references),
        );

        sort($references, SORT_STRING);

        return $references;
    }

    /**
     * Return bounded manifest-version metadata explicitly referenced by deterministic evidence.
     *
     * @return list<array<string, mixed>>
     */
    private function manifestHistory(
        Project $project,
        KnowledgeImprovementCandidate $candidate,
    ): array {
        $manifestIds = [];

        foreach ($this->candidateEvidence($candidate) as $item) {
            foreach ([
                'knowledge_source_manifest_id',
                'superseded_manifest_id',
                'replacement_manifest_id',
            ] as $key) {
                $id = $item[$key] ?? null;

                if (is_int($id) && $id > 0) {
                    $manifestIds[] = $id;
                }
            }
        }

        $manifestIds = array_slice(
            array_values(array_unique($manifestIds)),
            0,
            8,
        );

        if ($manifestIds === []) {
            return [];
        }

        $manifests = KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->whereIn('id', $manifestIds)
            ->orderBy('id')
            ->get();

        $history = [];

        foreach ($manifests as $manifest) {
            $history[] = [
                'id' => $manifest->id,
                'source_type' => $manifest->source_type,
                'source_reference' => $manifest->source_reference,
                'content_hash' => $manifest->content_hash,
                'superseded_by_id' => $manifest->superseded_by_id,
                'last_verified_at' => $this->serializeDateAttribute(
                    $manifest,
                    'last_verified_at',
                ),
                'superseded_at' => $this->serializeDateAttribute(
                    $manifest,
                    'superseded_at',
                ),
            ];
        }

        return $history;
    }

    /**
     * Serialize one model date attribute without relying on dynamic-property inference.
     */
    private function serializeDateAttribute(
        Model $model,
        string $attribute,
    ): ?string {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface
            ? $value->toIso8601String()
            : null;
    }

    /**
     * Read one bounded project-local Obsidian source after realpath containment validation.
     */
    private function readProjectSource(
        Project $project,
        string $reference,
        int $characterLimit,
    ): ?string {
        if (
            $characterLimit <= 0
            || $reference === ''
            || Str::contains(
                $reference,
                ["\0", '..', '\\'],
            )
            || Str::startsWith($reference, '/')
            || preg_match(
                '/^[A-Za-z]:\//',
                $reference,
            ) === 1
        ) {
            return null;
        }

        $vault = config('aios.obsidian_vault_path');

        if (! is_string($vault) || $vault === '') {
            return null;
        }

        $directory = realpath(
            $vault
            .'/Projects/'
            .Str::slug($project->name),
        );

        if (
            $directory === false
            || ! $this->files->isDirectory($directory)
        ) {
            return null;
        }

        $path = realpath(
            $directory
            .DIRECTORY_SEPARATOR
            .$reference,
        );

        $directoryPrefix =
            rtrim($directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR;

        if (
            $path === false
            || ! $this->files->isFile($path)
            || ! Str::startsWith(
                $path,
                $directoryPrefix,
            )
        ) {
            return null;
        }

        try {
            return mb_substr(
                $this->files->get($path),
                0,
                $characterLimit,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Expose aggregate non-sensitive evidence when the normalized pattern recurs in other projects.
     *
     * @return array<string, mixed>|null
     */
    private function crossProjectPatternEvidence(
        KnowledgeImprovementCandidate $candidate,
    ): ?array {
        $query = $this->matchingOtherCandidates(
            $candidate,
        );

        $otherProjectCount = (clone $query)
            ->distinct()
            ->count('project_id');

        if ($otherProjectCount === 0) {
            return null;
        }

        return [
            'matching_project_count' => $otherProjectCount + 1,
            'matching_candidate_count' => (clone $query)->count() + 1,
            'combined_occurrence_count' => (int) (clone $query)
                ->sum('occurrence_count')
                + (int) $candidate->occurrence_count,
            'pattern' => [
                'source_kind' => $candidate->source_kind,
                'failure_code' => $candidate->failure_code,
                'affected_role' => $candidate->affected_role,
                'affected_area' => $candidate->affected_area,
                'target_type' => (string) $candidate
                    ->getRawOriginal('target_type'),
            ],
        ];
    }

    /**
     * Count other projects containing the same normalized durable knowledge pattern.
     */
    private function otherMatchingProjectCount(
        KnowledgeImprovementCandidate $candidate,
    ): int {
        return $this->matchingOtherCandidates(
            $candidate,
        )
            ->distinct()
            ->count('project_id');
    }

    /**
     * Build the deterministic cross-project query without exposing foreign project content.
     *
     * @return Builder<KnowledgeImprovementCandidate>
     */
    private function matchingOtherCandidates(
        KnowledgeImprovementCandidate $candidate,
    ): Builder {
        $query = KnowledgeImprovementCandidate::query()
            ->where(
                'project_id',
                '<>',
                $candidate->project_id,
            )
            ->where(
                'source_kind',
                $candidate->source_kind,
            )
            ->where(
                'failure_code',
                $candidate->failure_code,
            )
            ->where(
                'target_type',
                $candidate->getRawOriginal('target_type'),
            );

        $candidate->affected_role === null
            ? $query->whereNull('affected_role')
            : $query->where(
                'affected_role',
                $candidate->affected_role,
            );

        $candidate->affected_area === null
            ? $query->whereNull('affected_area')
            : $query->where(
                'affected_area',
                $candidate->affected_area,
            );

        return $query;
    }

    /**
     * Create a disposable empty workspace while preserving the persisted project ID for attribution.
     *
     * @return array{0: Project, 1: string}
     */
    private function isolatedExecutionProject(
        Project $project,
    ): array {
        $path = $this->paths->resolve(
            '.aios-knowledge-architect/'
            .Str::uuid(),
        );

        $this->files->ensureDirectoryExists($path);

        $executionProject = clone $project;

        $executionProject->setAttribute(
            'path',
            $path,
        );

        return [$executionProject, $path];
    }

    /**
     * Build the provider prompt with the deterministic assembled-context JSON marker required by Context Budget.
     */
    private function prompt(
        AssembledAgentContext $context,
    ): string {
        $schema = [
            'schema_version' => 1,
            'action' => 'enrich|no_change',
            'evidence_summary' => 'non-empty string, maximum 4000 characters',
            'proposed_change' => 'non-empty string when action=enrich, otherwise null',
            'confidence' => 'low|medium|high',
        ];

        return implode("\n", [
            'Knowledge Architect advisory execution.',
            'Use only the supplied AIOS context. Do not inspect the filesystem.',
            'Do not write or mutate documentation, Obsidian, Skills, rules, Agent configuration, Tasks, Tickets, workflow state, Git state, source manifests, or any authoritative knowledge.',
            'Do not return chain-of-thought or hidden reasoning.',
            'Return exactly one JSON object and no prose.',
            'Required advisory schema: '
                .json_encode(
                    $schema,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES,
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
     * Convert rejected semantic output into the exact AgentRunRecorder execution shape.
     *
     * @return array{
     *     exit_code: int,
     *     output: string,
     *     error_output: string,
     *     external_run_id: string|null,
     *     usage: array<string, mixed>|null,
     *     provider_metadata: array<string, mixed>
     * }
     */
    private function rejectedExecution(
        NormalizedExecutionResult $execution,
        string $message,
    ): array {
        return [
            'exit_code' => 1,
            'output' => $execution->output,
            'error_output' => $message,
            'external_run_id' => $execution->externalRunId,
            'usage' => $execution->usage,
            'provider_metadata' => $execution->providerMetadata,
        ];
    }

    /**
     * Audit global-Agent or harness unavailability without rolling back deterministic detection.
     */
    private function recordUnavailable(
        Project $project,
        string $stage,
        Throwable $exception,
    ): void {
        $this->audit->record(
            'knowledge_architect.unavailable',
            [
                'stage' => $stage,
                'reason' => $exception->getMessage(),
            ],
            $project,
        );
    }

    /**
     * Audit failed advisory execution without changing source knowledge or workflow state.
     */
    private function recordExecutionFailure(
        Project $project,
        KnowledgeImprovementCandidate $candidate,
        AgentRun $run,
        string $failureType,
        string $reason,
    ): void {
        $this->audit->record(
            'knowledge_architect.execution_failed',
            [
                'candidate_id' => $candidate->id,
                'agent_run_id' => $run->id,
                'failure_type' => $failureType,
                'reason' => $reason,
            ],
            $project,
        );
    }

    /**
     * Determine whether a string is a canonical SHA-256 hexadecimal digest.
     */
    private function isSha256(string $value): bool
    {
        return preg_match(
            '/\A[a-f0-9]{64}\z/',
            $value,
        ) === 1;
    }
}
