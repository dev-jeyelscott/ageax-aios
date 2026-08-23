<?php

namespace App\Services;

use App\AgentRole;
use App\Models\GlobalKnowledgePattern;
use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use App\Models\Review;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\ReviewStatus;
use App\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read and write project-scoped Obsidian knowledge with bounded deterministic retrieval.
 *
 * @phpstan-type RetrievalCandidate array{
 *     source_id: string,
 *     source_type: 'obsidian'|'global_pattern',
 *     source_reference: string,
 *     content: string,
 *     rank: int,
 *     ranking_reason: string,
 *     relationship: string,
 *     temporal_rank: int,
 *     temporal_status: string,
 *     knowledge_source_manifest_id: ?int,
 *     content_hash: string,
 *     superseded_by_id: ?int,
 *     global_knowledge_pattern_id: ?int,
 *     pattern_key: ?string,
 *     name: ?string,
 *     category: ?string,
 *     version: ?int,
 *     source_project_id: ?int,
 *     source_candidate_id: ?int,
 *     source_evidence_hash: ?string,
 *     approved_by_user_id: ?int
 * }
 */
class ObsidianProjectNotes
{
    private const int TaskBriefRank = 10;

    private const int StateRank = 20;

    private const int ExplicitLinkRank = 30;

    private const int RelevantPathRank = 40;

    private const int SameAffectedAreaRank = 50;

    private const int CurrentAdrRank = 60;

    private const int ApprovedGlobalPatternRank = 70;

    private const int RelatedManifestCandidateMultiplier = 8;

    /**
     * Inject the filesystem used for project-local note IO.
     */
    public function __construct(private Filesystem $files) {}

    /**
     * Write the compact project overview and refresh STATE.md.
     */
    public function writeOverview(Project $project): ?string
    {
        $vault = config('aios.obsidian_vault_path');
        if (! is_string($vault) || $vault === '') {
            return null;
        }

        $directory = $vault.'/Projects/'.Str::slug($project->name);
        $this->files->ensureDirectoryExists($directory);
        $path = $directory.'/Project Overview.md';
        $status = ProjectStatus::from($project->getRawOriginal('status'))->value;
        $this->files->put($path, "# {$project->name}\n\n- Path: `{$project->path}`\n- Status: {$status}\n");
        $this->writeState($project);

        return $path;
    }

    /**
     * Persist one completed task note and refresh current project state.
     */
    public function writeTaskCompletion(Task $task, string $implementationSummary, ?TaskAttempt $attempt = null, ?string $reviewSummary = null): ?string
    {
        $vault = config('aios.obsidian_vault_path');
        if (! is_string($vault) || $vault === '') {
            return null;
        }

        $task->loadMissing('project', 'phase');
        $directory = $vault.'/Projects/'.Str::slug($task->project->name).'/Tasks';
        $this->files->ensureDirectoryExists($directory);
        $path = $directory.'/'.$task->key.' - '.Str::slug($task->title).'.md';
        $criteria = collect($this->acceptanceCriteria($task))->map(fn (string $criterion): string => "- {$criterion}")->implode("\n");
        $attemptDetails = $attempt === null
            ? ''
            : "\n- Attempt: {$attempt->number}\n- Commit: ".($attempt->commit_sha ?? 'Not recorded');
        $reviewDetails = filled($reviewSummary) ? "\n\n## Reviewer approval\n\n{$reviewSummary}" : '';
        $completedAt = CarbonImmutable::parse($task->getRawOriginal('completed_at') ?? now())->toIso8601String();
        $content = "# {$task->key}: {$task->title}\n\n## Implementation summary\n\n{$implementationSummary}\n\n## Objective\n\n{$task->objective}\n\n## Acceptance criteria\n\n{$criteria}\n\n## Completion\n\n- Completed: ".$completedAt.$attemptDetails.$reviewDetails."\n";
        $this->files->put($path, $content);
        $this->writeState($task->project);

        return $path;
    }

    /**
     * Write the deterministic Task brief consumed by bounded task retrieval.
     */
    public function writeTaskBrief(Task $task): ?string
    {
        $task->loadMissing('project', 'phase');
        $criteria = collect($this->taskStringList($task, 'acceptance_criteria'))->map(fn (string $criterion): string => "- {$criterion}")->implode("\n");
        $paths = collect($this->taskStringList($task, 'relevant_paths'))->map(fn (string $path): string => "- `{$path}`")->implode("\n");
        $commands = collect($this->taskStringList($task, 'verification_commands'))->map(fn (string $command): string => "- `{$command}`")->implode("\n");
        $constraints = collect($this->taskStringList($task, 'constraints'))->map(fn (string $constraint): string => "- {$constraint}")->implode("\n");
        $context = $this->taskArray($task, 'context_capsule');
        $links = collect(is_array($context['obsidian_notes'] ?? null) ? $context['obsidian_notes'] : [])
            ->filter(fn (mixed $path): bool => is_string($path))
            ->map(fn (string $path): string => "- [[{$path}]]")
            ->implode("\n");

        try {
            $path = $this->writeProjectNote($task->project, 'Task Briefs', $this->taskBriefFilename($task), "# {$task->key}: {$task->title}\n\n## Objective\n\n{$task->objective}\n\n## Acceptance criteria\n\n{$criteria}\n\n## Relevant paths\n\n".($paths !== '' ? $paths : '- Inspect the current implementation.')."\n\n## Verification commands\n\n".($commands !== '' ? $commands : '- Run focused existing coverage.')."\n\n## Constraints\n\n".($constraints !== '' ? $constraints : '- Follow AGENTS.md and existing conventions.')."\n\n## Intentional notes\n\n".($links !== '' ? $links : '- None.')."\n");
            $this->writeState($task->project);
        } catch (Throwable) {
            return null;
        }

        return $path;
    }

    /**
     * Persist the latest uploaded roadmap into project-scoped Obsidian knowledge.
     */
    public function writeRoadmapUpload(Roadmap $roadmap): ?string
    {
        $roadmap->loadMissing('project');

        $path = $this->writeProjectNote(
            $roadmap->project,
            'Roadmaps',
            'Latest Upload.md',
            "# Roadmap: {$roadmap->original_filename}\n\n## Uploaded roadmap\n\n{$roadmap->content}\n",
        );
        $this->writeState($roadmap->project);

        return $path;
    }

    /**
     * Persist the validated Project Manager roadmap decomposition.
     *
     * @param  array<string, mixed>  $plan
     */
    public function writeRoadmapPlan(Project $project, array $plan): ?string
    {
        $path = $this->writeProjectNote(
            $project,
            'Roadmaps',
            'Implementation Plan.md',
            "# Implementation Plan\n\nThis is the validated Project Manager decomposition of the latest roadmap. Verify the repository before relying on a completion status.\n\n```json\n".json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n```\n",
        );
        $this->writeState($project);

        return $path;
    }

    /**
     * Persist Project Manager knowledge, decisions, handoff, and phase notes.
     *
     * @param  array<string, mixed>  $knowledge
     * @param  array<int, array<string, mixed>>  $phases
     */
    public function writeProjectManagerKnowledge(Project $project, array $knowledge, array $phases): void
    {
        $overview = is_string($knowledge['overview'] ?? null) ? $knowledge['overview'] : 'No additional Project Manager overview was provided.';
        $constraints = collect($this->stringList($knowledge['constraints'] ?? null))
            ->map(fn (string $constraint): string => "- {$constraint}")
            ->implode("\n");
        $decisions = collect($this->architectureDecisions($knowledge['architecture_decisions'] ?? null))
            ->map(fn (array $decision): string => "## {$decision['title']}\n\n{$decision['rationale']}")
            ->implode("\n\n");
        $handoff = is_string($knowledge['handoff'] ?? null) ? $knowledge['handoff'] : 'Start with the next eligible task and verify the repository before relying on this plan.';

        $this->writeProjectNote($project, 'Roadmaps', 'Project Manager Knowledge.md', "# Project Manager Knowledge\n\n## Overview\n\n{$overview}\n\n## Constraints\n\n".($constraints !== '' ? $constraints : '- None recorded.')."\n");
        $this->writeProjectNote($project, 'Decisions', 'Project Manager Decisions.md', "# Project Manager Decisions\n\n".($decisions !== '' ? $decisions : 'No architecture decisions were recorded.')."\n");
        $this->writeProjectNote($project, 'Handoffs', 'Project Manager Handoff.md', "# Project Manager Handoff\n\n{$handoff}\n");

        foreach ($phases as $position => $phase) {
            if (! is_string($phase['title'] ?? null) || ! is_string($phase['objective'] ?? null) || ! is_array($phase['tasks'] ?? null)) {
                continue;
            }

            $tasks = collect($phase['tasks'])
                ->filter(fn (mixed $task): bool => is_array($task) && is_string($task['title'] ?? null) && is_string($task['objective'] ?? null))
                ->map(fn (array $task): string => "- **{$task['title']}** — {$task['objective']}")
                ->implode("\n");
            $filename = str_pad((string) ($position + 1), 2, '0', STR_PAD_LEFT).' - '.Str::slug($phase['title']).'.md';
            $this->writeProjectNote($project, 'Phases', $filename, "# {$phase['title']}\n\n## Objective\n\n{$phase['objective']}\n\n## Ordered tasks\n\n".($tasks !== '' ? $tasks : '- No tasks recorded.')."\n");
        }

        $this->writeState($project);
    }

    /**
     * Normalize a generic string-list payload.
     *
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * Normalize structured Project Manager architecture decisions.
     *
     * @return array<int, array{title: string, rationale: string}>
     */
    private function architectureDecisions(mixed $decisions): array
    {
        if (! is_array($decisions)) {
            return [];
        }

        return array_values(array_filter($decisions, fn (mixed $decision): bool => is_array($decision) && is_string($decision['title'] ?? null) && is_string($decision['rationale'] ?? null)));
    }

    /**
     * Persist one Reviewer outcome and its structured findings.
     */
    public function writeReview(Task $task, Review $review): ?string
    {
        $task->loadMissing('project');
        $review->loadMissing('findings');
        $attempt = $review->attempt()->firstOrFail();
        $findings = $review->findings
            ->map(fn ($finding): string => "### {$finding->severity}: ".($finding->location ?? 'General')."\n\n- Current: {$finding->current_implementation}\n- Expected: {$finding->expected_implementation}\n- Why: {$finding->why_incorrect}\n- Required fix: {$finding->required_fix}\n- Verify: {$finding->verification_requirement}\n")
            ->implode("\n");
        $status = ReviewStatus::from($review->getRawOriginal('status'))->value;
        $content = "# Review: {$task->key}\n\n- Outcome: {$status}\n- Attempt: {$attempt->number}\n- Commit: ".($attempt->commit_sha ?? 'Not recorded')."\n\n## Summary\n\n".($review->summary ?? 'No summary provided.')."\n";

        if ($findings !== '') {
            $content .= "\n## Findings\n\n{$findings}";
        }

        $path = $this->writeProjectNote($task->project, 'Reviews', $task->key.'.md', $content);
        $this->writeState($task->project);

        return $path;
    }

    /**
     * Return the bounded project knowledge used by roadmap flows.
     *
     * @return array<string, string>
     */
    public function projectKnowledge(Project $project): array
    {
        return $this->roadmapKnowledge($project);
    }

    /**
     * Read only the fixed roadmap knowledge set.
     *
     * @return array<string, string>
     */
    public function roadmapKnowledge(Project $project): array
    {
        return $this->readNotes($project, [
            'STATE.md',
            'Project Overview.md',
            'Roadmaps/Latest Upload.md',
            'Roadmaps/Implementation Plan.md',
            'Roadmaps/Project Manager Knowledge.md',
            'Decisions/Project Manager Decisions.md',
            'Handoffs/Project Manager Handoff.md',
        ]);
    }

    /**
     * Return roadmap knowledge together with its compact retrieval manifest.
     *
     * @return array{notes: array<string, string>, manifest: array{role: string, selected_note_paths: array<int, string>, character_count: int, retrieval_reason: string}}
     */
    public function roadmapRetrieval(Project $project): array
    {
        $notes = $this->roadmapKnowledge($project);

        return [
            'notes' => $notes,
            'manifest' => [
                'role' => AgentRole::ProjectManager->value,
                'selected_note_paths' => array_keys($notes),
                'character_count' => array_sum(array_map(Str::length(...), $notes)),
                'retrieval_reason' => 'targeted project roadmap knowledge only',
            ],
        ];
    }

    /**
     * Build deterministic Task knowledge from bounded project notes and approved global patterns.
     *
     * @return array{
     *     notes: array<string, string>,
     *     approved_patterns: list<array<string, mixed>>,
     *     manifest: array{
     *         role: string,
     *         selected_note_paths: list<string>,
     *         selected_sources: list<array<string, mixed>>,
     *         character_count: int,
     *         retrieval_reason: string
     *     }
     * }
     */
    public function taskRetrieval(Task $task, AgentRole $role): array
    {
        $task->loadMissing('project');
        $candidates = [];
        $taskBriefReference = 'Task Briefs/'.$this->taskBriefFilename($task);

        $taskBrief = $this->noteCandidate(
            $task->project,
            $taskBriefReference,
            self::TaskBriefRank,
            'current_task_brief',
            'current_task',
        );
        $state = $this->noteCandidate(
            $task->project,
            'STATE.md',
            self::StateRank,
            'current_state',
            'current_project_state',
        );

        $this->addCandidate($candidates, $taskBrief);
        $this->addCandidate($candidates, $state);

        $initialContents = [];

        foreach ([$taskBrief, $state] as $candidate) {
            if (is_array($candidate)) {
                $initialContents[$candidate['source_reference']] = $candidate['content'];
            }
        }

        foreach ($this->explicitNotePaths($task, $initialContents) as $path) {
            $this->addCandidate(
                $candidates,
                $this->noteCandidate(
                    $task->project,
                    $path,
                    self::ExplicitLinkRank,
                    'explicit_link',
                    'task_context_or_direct_initial_note_link',
                ),
            );
        }

        foreach ($this->relatedManifestReferences($task) as $related) {
            $this->addCandidate(
                $candidates,
                $this->noteCandidate(
                    $task->project,
                    $related['path'],
                    $related['rank'],
                    $related['ranking_reason'],
                    $related['relationship'],
                ),
            );
        }

        foreach ($this->approvedGlobalPatternCandidates($role) as $candidate) {
            $this->addCandidate($candidates, $candidate);
        }

        $selected = $this->selectCandidates(array_values($candidates));
        $notes = [];
        $approvedPatterns = [];
        $selectedSources = [];

        foreach ($selected as $candidate) {
            if ($candidate['source_type'] === 'obsidian') {
                $notes[$candidate['source_reference']] = $candidate['content'];
            } else {
                $approvedPatterns[] = $this->approvedPatternPayload($candidate);
            }

            $selectedSources[] = $this->sourceManifestEntry($candidate);
        }

        return [
            'notes' => $notes,
            'approved_patterns' => $approvedPatterns,
            'manifest' => [
                'role' => $role->value,
                'selected_note_paths' => array_keys($notes),
                'selected_sources' => $selectedSources,
                'character_count' => array_sum(array_column($selectedSources, 'character_count')),
                'retrieval_reason' => 'deterministically ranked current task knowledge, targeted project sources, and operator-approved reusable patterns only',
            ],
        ];
    }

    /**
     * Write current durable project and next-task state.
     */
    public function writeState(Project $project): ?string
    {
        $directory = $this->projectDirectory($project);
        if ($directory === null) {
            return null;
        }

        $roadmap = $project->roadmaps()->latest('id')->first(['id', 'project_id', 'original_filename', 'status', 'processed_at']);
        $task = $project->tasks()->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])->orderBy('position')->first(['id', 'project_id', 'key', 'title', 'status']);
        $projectStatus = ProjectStatus::from($project->getRawOriginal('status'))->value;
        $roadmapState = $roadmap === null
            ? '- None uploaded.'
            : "- {$roadmap->original_filename}: {$roadmap->getRawOriginal('status')}".($roadmap->processed_at === null ? '' : ' (processed '.CarbonImmutable::parse($roadmap->getRawOriginal('processed_at'))->toIso8601String().')');
        $taskState = $task === null
            ? '- No pending task.'
            : "- {$task->key}: {$task->title} ({$task->getRawOriginal('status')})";

        try {
            $this->files->ensureDirectoryExists($directory);
            $path = $directory.'/STATE.md';
            $this->files->put($path, "# Project State\n\n## Project\n\n- Status: {$projectStatus}\n- Git: ".($project->git_status ?? 'unknown')."\n\n## Latest roadmap\n\n{$roadmapState}\n\n## Next task\n\n{$taskState}\n");
        } catch (Throwable) {
            return null;
        }

        return $path;
    }

    /**
     * Add one candidate while preserving only the strongest deterministic rank for a source identity.
     *
     * @param  array<string, RetrievalCandidate>  $candidates
     * @param  RetrievalCandidate|null  $candidate
     */
    private function addCandidate(array &$candidates, ?array $candidate): void
    {
        if ($candidate === null) {
            return;
        }

        $existing = $candidates[$candidate['source_id']] ?? null;

        if (
            $existing === null
            || $this->compareCandidates($candidate, $existing) < 0
        ) {
            $candidates[$candidate['source_id']] = $candidate;
        }
    }

    /**
     * Build one safe project-local note candidate without traversing the vault.
     *
     * @return RetrievalCandidate|null
     */
    private function noteCandidate(
        Project $project,
        string $relativePath,
        int $rank,
        string $rankingReason,
        string $relationship,
    ): ?array {
        $relativePath = $this->normalizeNoteReference($relativePath);
        $directory = $this->projectDirectory($project);

        if ($relativePath === null || $directory === null || ! $this->files->isDirectory($directory)) {
            return null;
        }

        $path = $this->safeProjectNotePath($directory, $relativePath);

        if ($path === null) {
            return null;
        }

        try {
            $content = $this->files->get($path);
        } catch (Throwable) {
            return null;
        }

        if ($content === '') {
            return null;
        }

        $temporal = $this->temporalEvidence($project, $relativePath, $content);

        return [
            'source_id' => 'obsidian:'.$relativePath,
            'source_type' => 'obsidian',
            'source_reference' => $relativePath,
            'content' => $content,
            'rank' => $rank,
            'ranking_reason' => $rankingReason,
            'relationship' => $relationship,
            ...$temporal,
            'global_knowledge_pattern_id' => null,
            'pattern_key' => null,
            'name' => null,
            'category' => null,
            'version' => null,
            'source_project_id' => null,
            'source_candidate_id' => null,
            'source_evidence_hash' => null,
            'approved_by_user_id' => null,
        ];
    }

    /**
     * Resolve explicit Task and one-hop initial-note links without following links recursively.
     *
     * @param  array<string, string>  $initialNotes
     * @return list<string>
     */
    private function explicitNotePaths(Task $task, array $initialNotes): array
    {
        $paths = [];
        $contextCapsule = $this->taskArray($task, 'context_capsule');

        if (is_array($contextCapsule['obsidian_notes'] ?? null)) {
            $paths = $contextCapsule['obsidian_notes'];
        }

        foreach ($initialNotes as $content) {
            preg_match_all('/\[\[([^\]|#]+)(?:#[^\]|]*)?(?:\|[^\]]*)?\]\]/', $content, $matches);
            $paths = [...$paths, ...$matches[1]];
        }

        $normalizedPaths = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $normalized = $this->normalizeNoteReference($path);

            if ($normalized !== null) {
                $normalizedPaths[] = $normalized;
            }
        }

        $normalizedPaths = array_values(array_unique($normalizedPaths));
        sort($normalizedPaths, SORT_STRING);

        return $normalizedPaths;
    }

    /**
     * Discover only current manifest references whose source identity proves a Task path relationship.
     *
     * @return list<array{path: string, rank: int, ranking_reason: string, relationship: string}>
     */
    private function relatedManifestReferences(Task $task): array
    {
        $relevantPaths = $this->relevantPaths($task);

        if ($relevantPaths === []) {
            return [];
        }

        $needles = [];

        foreach ($relevantPaths as $path) {
            $needles[] = $path;
            $area = $this->affectedArea($path);

            if ($area !== null && Str::contains($area, '/')) {
                $needles[] = $area;
            }
        }

        $needles = array_values(array_unique(array_filter(
            $needles,
            fn (string $needle): bool => Str::length($needle) >= 4,
        )));

        if ($needles === []) {
            return [];
        }

        $manifests = KnowledgeSourceManifest::query()
            ->whereBelongsTo($task->project)
            ->where('source_type', 'obsidian')
            ->whereNull('superseded_at')
            ->where(function ($query) use ($needles): void {
                foreach ($needles as $needle) {
                    $query->orWhere('source_reference', 'like', '%'.$needle.'%');
                }
            })
            ->orderBy('source_reference')
            ->limit(max(
                $this->maximumSources() * self::RelatedManifestCandidateMultiplier,
                self::RelatedManifestCandidateMultiplier,
            ))
            ->get(['source_reference']);

        $related = [];

        foreach ($manifests as $manifest) {
            $reference = $this->normalizeNoteReference((string) $manifest->source_reference);

            if ($reference === null) {
                continue;
            }

            $relationship = $this->manifestRelationship($reference, $relevantPaths);

            if ($relationship !== null) {
                $related[$reference] = [
                    'path' => $reference,
                    ...$relationship,
                ];
            }
        }

        ksort($related, SORT_STRING);

        return array_values($related);
    }

    /**
     * Classify one manifest reference only when exact source identity proves relevance.
     *
     * @param  list<string>  $relevantPaths
     * @return array{rank: int, ranking_reason: string, relationship: string}|null
     */
    private function manifestRelationship(string $reference, array $relevantPaths): ?array
    {
        $isDecision = Str::startsWith($reference, ['ADR/', 'Decisions/']);
        $sameArea = null;

        foreach ($relevantPaths as $path) {
            if ($this->sourceReferenceContainsIdentity($reference, $path)) {
                if ($isDecision) {
                    return [
                        'rank' => self::CurrentAdrRank,
                        'ranking_reason' => 'current_adr',
                        'relationship' => 'decision_source_identity_matches_relevant_path:'.$path,
                    ];
                }

                return [
                    'rank' => self::RelevantPathRank,
                    'ranking_reason' => 'relevant_path',
                    'relationship' => 'source_identity_matches_relevant_path:'.$path,
                ];
            }

            $area = $this->affectedArea($path);

            if (
                $sameArea === null
                && $area !== null
                && Str::contains($area, '/')
                && $this->sourceReferenceContainsIdentity($reference, $area)
            ) {
                $sameArea = $area;
            }
        }

        if ($sameArea === null) {
            return null;
        }

        if ($isDecision) {
            return [
                'rank' => self::CurrentAdrRank,
                'ranking_reason' => 'current_adr',
                'relationship' => 'decision_source_identity_matches_affected_area:'.$sameArea,
            ];
        }

        return [
            'rank' => self::SameAffectedAreaRank,
            'ranking_reason' => 'same_affected_area',
            'relationship' => 'source_identity_matches_affected_area:'.$sameArea,
        ];
    }

    /**
     * Return active operator-approved global patterns applicable to the recipient role.
     *
     * @return list<RetrievalCandidate>
     */
    private function approvedGlobalPatternCandidates(AgentRole $role): array
    {
        $patterns = GlobalKnowledgePattern::query()
            ->where('enabled', true)
            ->whereNull('superseded_at')
            ->whereNotNull('approved_by_user_id')
            ->whereHas('approvedBy')
            ->whereJsonContains('applicable_roles', $role->value)
            ->orderBy('pattern_key')
            ->orderByDesc('version')
            ->orderBy('id')
            ->limit(max($this->maximumSources() * 4, 4))
            ->get();

        return $patterns
            ->map(function (GlobalKnowledgePattern $pattern): array {
                $reference = 'global-pattern:'
                    .$pattern->pattern_key
                    .':v'
                    .$pattern->version;

                return [
                    'source_id' => $reference,
                    'source_type' => 'global_pattern',
                    'source_reference' => $reference,
                    'content' => (string) $pattern->validated_guidance,
                    'rank' => self::ApprovedGlobalPatternRank,
                    'ranking_reason' => 'approved_global_pattern',
                    'relationship' => 'operator_approved_and_role_applicable',
                    'temporal_rank' => 0,
                    'temporal_status' => 'current',
                    'knowledge_source_manifest_id' => null,
                    'content_hash' => hash('sha256', (string) $pattern->validated_guidance),
                    'superseded_by_id' => null,
                    'global_knowledge_pattern_id' => $pattern->id,
                    'pattern_key' => (string) $pattern->pattern_key,
                    'name' => (string) $pattern->name,
                    'category' => (string) $pattern->category,
                    'version' => (int) $pattern->version,
                    'source_project_id' => (int) $pattern->source_project_id,
                    'source_candidate_id' => (int) $pattern->source_candidate_id,
                    'source_evidence_hash' => (string) $pattern->source_evidence_hash,
                    'approved_by_user_id' => (int) $pattern->approved_by_user_id,
                ];
            })
            ->all();
    }

    /**
     * Apply one shared deterministic source-count and character budget after ranking.
     *
     * @param  list<RetrievalCandidate>  $candidates
     * @return list<RetrievalCandidate&array{character_count: int}>
     */
    private function selectCandidates(array $candidates): array
    {
        usort($candidates, $this->compareCandidates(...));

        $maximumSources = $this->maximumSources();
        $remainingCharacters = $this->maximumCharacters();
        $perSourceCharacters = $this->maximumSourceCharacters();
        $selected = [];

        if (
            $maximumSources === 0
            || $remainingCharacters === 0
            || $perSourceCharacters === 0
        ) {
            return [];
        }

        foreach ($candidates as $candidate) {
            if (count($selected) >= $maximumSources || $remainingCharacters === 0) {
                break;
            }

            $allowedCharacters = min(
                $perSourceCharacters,
                $remainingCharacters,
            );
            $content = Str::substr(
                $candidate['content'],
                0,
                $allowedCharacters,
            );

            if ($content === '') {
                continue;
            }

            $characterCount = Str::length($content);
            $selected[] = [
                ...$candidate,
                'content' => $content,
                'character_count' => $characterCount,
            ];
            $remainingCharacters -= $characterCount;
        }

        return $selected;
    }

    /**
     * Compare candidates using precedence, temporal state, stable source identity, then newest version.
     *
     * @param  RetrievalCandidate  $left
     * @param  RetrievalCandidate  $right
     */
    private function compareCandidates(array $left, array $right): int
    {
        $comparison = [
            $left['rank'],
            $left['temporal_rank'],
            $left['source_reference'],
        ] <=> [
            $right['rank'],
            $right['temporal_rank'],
            $right['source_reference'],
        ];

        if ($comparison !== 0) {
            return $comparison;
        }

        return ($right['version'] ?? 0) <=> ($left['version'] ?? 0);
    }

    /**
     * Resolve current, drifted, untracked, or superseded manifest evidence for exact note bytes.
     *
     * @return array{
     *     temporal_rank: int,
     *     temporal_status: string,
     *     knowledge_source_manifest_id: ?int,
     *     content_hash: string,
     *     superseded_by_id: ?int
     * }
     */
    private function temporalEvidence(Project $project, string $reference, string $content): array
    {
        $contentHash = hash('sha256', $content);
        $query = KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->where('source_type', 'obsidian')
            ->where('source_reference', $reference);

        $current = (clone $query)
            ->whereNull('superseded_at')
            ->where('content_hash', $contentHash)
            ->orderByDesc('id')
            ->first(['id', 'content_hash', 'superseded_by_id']);

        if ($current instanceof KnowledgeSourceManifest) {
            return [
                'temporal_rank' => 0,
                'temporal_status' => 'current',
                'knowledge_source_manifest_id' => $current->id,
                'content_hash' => $contentHash,
                'superseded_by_id' => $current->superseded_by_id,
            ];
        }

        $historical = (clone $query)
            ->whereNotNull('superseded_at')
            ->where('content_hash', $contentHash)
            ->orderByDesc('id')
            ->first(['id', 'content_hash', 'superseded_by_id']);

        if ($historical instanceof KnowledgeSourceManifest) {
            return [
                'temporal_rank' => 2,
                'temporal_status' => 'superseded',
                'knowledge_source_manifest_id' => $historical->id,
                'content_hash' => $contentHash,
                'superseded_by_id' => $historical->superseded_by_id,
            ];
        }

        $hasCurrentReference = (clone $query)
            ->whereNull('superseded_at')
            ->exists();

        return [
            'temporal_rank' => 1,
            'temporal_status' => $hasCurrentReference ? 'drifted' : 'untracked',
            'knowledge_source_manifest_id' => null,
            'content_hash' => $contentHash,
            'superseded_by_id' => null,
        ];
    }

    /**
     * Build the bounded approved-pattern payload exposed through existing approved documentation context.
     *
     * @param  RetrievalCandidate&array{character_count: int}  $candidate
     * @return array<string, mixed>
     */
    private function approvedPatternPayload(array $candidate): array
    {
        return [
            'global_knowledge_pattern_id' => $candidate['global_knowledge_pattern_id'],
            'pattern_key' => $candidate['pattern_key'],
            'name' => $candidate['name'],
            'category' => $candidate['category'],
            'version' => $candidate['version'],
            'validated_guidance' => $candidate['content'],
            'source_project_id' => $candidate['source_project_id'],
            'source_candidate_id' => $candidate['source_candidate_id'],
            'source_evidence_hash' => $candidate['source_evidence_hash'],
            'approved_by_user_id' => $candidate['approved_by_user_id'],
        ];
    }

    /**
     * Build one explainable manifest entry without embedding source content.
     *
     * @param  RetrievalCandidate&array{character_count: int}  $candidate
     * @return array<string, mixed>
     */
    private function sourceManifestEntry(array $candidate): array
    {
        return array_filter([
            'source_id' => $candidate['source_id'],
            'source_type' => $candidate['source_type'],
            'source_reference' => $candidate['source_reference'],
            'rank' => $candidate['rank'],
            'ranking_reason' => $candidate['ranking_reason'],
            'relationship' => $candidate['relationship'],
            'temporal_status' => $candidate['temporal_status'],
            'knowledge_source_manifest_id' => $candidate['knowledge_source_manifest_id'],
            'content_hash' => $candidate['content_hash'],
            'superseded_by_id' => $candidate['superseded_by_id'],
            'global_knowledge_pattern_id' => $candidate['global_knowledge_pattern_id'],
            'pattern_key' => $candidate['pattern_key'],
            'version' => $candidate['version'],
            'source_project_id' => $candidate['source_project_id'],
            'source_candidate_id' => $candidate['source_candidate_id'],
            'source_evidence_hash' => $candidate['source_evidence_hash'],
            'approved_by_user_id' => $candidate['approved_by_user_id'],
            'character_count' => $candidate['character_count'],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Normalize project-relative Task repository paths used only as deterministic matching evidence.
     *
     * @return list<string>
     */
    private function relevantPaths(Task $task): array
    {
        $paths = [];

        foreach ($this->taskStringList($task, 'relevant_paths') as $path) {
            $path = trim(str_replace('\\', '/', $path));
            $path = preg_replace('#^\./+#', '', $path) ?? $path;
            $path = rtrim($path, '/');

            if (
                $path === ''
                || Str::startsWith($path, '/')
                || preg_match('/^[A-Za-z]:\//', $path) === 1
                || in_array('.', explode('/', $path), true)
                || in_array('..', explode('/', $path), true)
            ) {
                continue;
            }

            $paths[] = $path;
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Resolve a deterministic affected directory from one relevant path without filesystem guessing.
     */
    private function affectedArea(string $path): ?string
    {
        $basename = basename($path);

        if (! Str::contains($basename, '.')) {
            return $path;
        }

        $directory = str_replace('\\', '/', dirname($path));

        return $directory === '.' || $directory === '' ? null : $directory;
    }

    /**
     * Require the normalized source identity to contain the exact normalized path or area text.
     */
    private function sourceReferenceContainsIdentity(string $reference, string $identity): bool
    {
        return Str::contains(
            Str::lower(str_replace('\\', '/', $reference)),
            Str::lower(str_replace('\\', '/', $identity)),
        );
    }

    /**
     * Normalize one project-local Markdown reference and reject traversal or absolute paths.
     */
    private function normalizeNoteReference(string $path): ?string
    {
        $path = trim($path);

        if (
            $path === ''
            || Str::contains($path, ["\0", '..', '\\'])
            || Str::startsWith($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1
        ) {
            return null;
        }

        return Str::endsWith($path, '.md') ? $path : $path.'.md';
    }

    /**
     * Return the configured maximum number of ranked knowledge sources.
     */
    private function maximumSources(): int
    {
        return max(0, (int) config('aios.obsidian_context_max_notes', 4));
    }

    /**
     * Return the configured aggregate character budget for ranked knowledge.
     */
    private function maximumCharacters(): int
    {
        return max(0, (int) config('aios.obsidian_context_max_characters', 2000));
    }

    /**
     * Return the configured per-source character budget for ranked knowledge.
     */
    private function maximumSourceCharacters(): int
    {
        return max(0, (int) config('aios.obsidian_context_max_note_characters', 2000));
    }

    /**
     * Read a fixed note list under existing roadmap retrieval quotas.
     *
     * @param  array<int, string>  $relativePaths
     * @return array<string, string>
     */
    private function readNotes(Project $project, array $relativePaths, int $alreadySelected = 0): array
    {
        $directory = $this->projectDirectory($project);
        if ($directory === null || ! $this->files->isDirectory($directory)) {
            return [];
        }

        $remainingCharacters = max(0, (int) config('aios.obsidian_context_max_characters', 24000));
        $perNoteCharacters = max(0, (int) config('aios.obsidian_context_max_note_characters', 4000));
        $maximumNotes = max(0, (int) config('aios.obsidian_context_max_notes', 12));
        $knowledge = [];

        foreach (array_unique($relativePaths) as $relativePath) {
            if (($alreadySelected + count($knowledge)) >= $maximumNotes || $remainingCharacters === 0) {
                break;
            }

            $path = $this->safeProjectNotePath($directory, $relativePath);
            if ($path === null) {
                continue;
            }

            $content = Str::substr($this->files->get($path), 0, min($perNoteCharacters, $remainingCharacters));
            if ($content === '') {
                continue;
            }

            $knowledge[$relativePath] = $content;
            $remainingCharacters -= Str::length($content);
        }

        return $knowledge;
    }

    /**
     * Resolve one Markdown note only when it remains inside the authorized project directory.
     */
    private function safeProjectNotePath(string $directory, string $relativePath): ?string
    {
        if (! Str::endsWith($relativePath, '.md') || Str::contains($relativePath, ['..', '\\']) || Str::startsWith($relativePath, '/')) {
            return null;
        }

        $projectDirectory = realpath($directory);
        $path = realpath($directory.'/'.$relativePath);
        if ($projectDirectory === false || $path === false || ! Str::startsWith($path, $projectDirectory.'/') || ! $this->files->isFile($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Decode the Task acceptance criteria from its durable raw JSON value.
     *
     * @return array<int, string>
     */
    private function acceptanceCriteria(Task $task): array
    {
        $decoded = json_decode((string) $task->getRawOriginal('acceptance_criteria'), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return [];
        }

        return array_map(fn (mixed $criterion): string => (string) $criterion, $decoded);
    }

    /**
     * Decode one Task JSON-array attribute from its durable raw value.
     *
     * @return array<string, mixed>
     */
    private function taskArray(Task $task, string $attribute): array
    {
        $decoded = json_decode((string) $task->getRawOriginal($attribute), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Decode and retain only strings from one Task JSON-array attribute.
     *
     * @return array<int, string>
     */
    private function taskStringList(Task $task, string $attribute): array
    {
        return array_values(array_filter($this->taskArray($task, $attribute), is_string(...)));
    }

    /**
     * Resolve the configured project-local Obsidian directory without creating it.
     */
    private function projectDirectory(Project $project): ?string
    {
        $vault = config('aios.obsidian_vault_path');

        return is_string($vault) && $vault !== '' ? $vault.'/Projects/'.Str::slug($project->name) : null;
    }

    /**
     * Write one project-scoped note under an explicit knowledge directory.
     */
    private function writeProjectNote(Project $project, string $directoryName, string $filename, string $content): ?string
    {
        $directory = $this->projectDirectory($project);
        if ($directory === null) {
            return null;
        }

        try {
            $directory .= '/'.$directoryName;
            $this->files->ensureDirectoryExists($directory);
            $path = $directory.'/'.$filename;
            $this->files->put($path, $content);
        } catch (Throwable) {
            return null;
        }

        return $path;
    }

    /**
     * Return the canonical project-local Task brief filename.
     */
    private function taskBriefFilename(Task $task): string
    {
        return $task->key.' - '.Str::slug($task->title).'.md';
    }
}
