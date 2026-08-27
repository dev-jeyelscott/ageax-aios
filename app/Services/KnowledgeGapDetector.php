<?php

namespace App\Services;

use App\KnowledgeImprovementTarget;
use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use App\Models\Task;
use App\TaskStatus;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Detect objective project-scoped knowledge gaps without invoking an LLM.
 *
 * @phpstan-type DetectorItem array{
 *     fingerprint_payload: array<string, mixed>,
 *     source_kind: string,
 *     failure_code: string,
 *     affected_role: ?string,
 *     affected_area: string,
 *     target_type: KnowledgeImprovementTarget,
 *     target_skill_slug: ?string,
 *     proposed_change: string,
 *     reference: array<string, mixed>,
 *     occurred_at: string,
 *     minimum_occurrences: int
 * }
 */
class KnowledgeGapDetector
{
    private const int MaxSourceReferenceCharacters = 500;

    /** @var list<string> */
    private const array StableKnowledgePrefixes = [
        'Architecture/',
        'Specifications/',
        'Decisions/',
        'ADR/',
        'Implementation/',
        'Notes/',
    ];

    /** @var list<string> */
    private const array ImplementationNotePrefixes = [
        'Implementation/',
        'Notes/',
    ];

    /** @var list<string> */
    private const array DecisionPrefixes = [
        'Decisions/',
        'ADR/',
    ];

    /** @var list<string> */
    private const array RepositoryPathPrefixes = [
        '.ai/',
        'app/',
        'bootstrap/',
        'config/',
        'database/',
        'resources/',
        'routes/',
        'tests/',
    ];

    /** @var list<string> */
    private const array RepositoryRootFiles = [
        'AGENTS.md',
        'CLAUDE.md',
        'MASTER-PROMPT.md',
        'composer.json',
        'package.json',
    ];

    /**
     * Inject only filesystem and workspace-boundary collaborators.
     */
    public function __construct(
        private Filesystem $files,
        private WorkspacePathResolver $paths,
    ) {}

    /**
     * Return deterministic point findings from current bounded project knowledge and manifest history.
     *
     * @return list<DetectorItem>
     */
    public function detect(Project $project): array
    {
        $directory = $this->projectKnowledgeDirectory($project);

        if ($directory === null) {
            return [];
        }

        $sources = $this->currentKnowledgeSources($project, $directory);
        $items = [
            ...$this->missingRequiredKnowledgeItems($project, $directory),
            ...$this->brokenWikiLinkItems($sources, $directory),
            ...$this->repositoryReferenceItems($project, $sources),
            ...$this->contentHashDriftItems($project),
            ...$this->supersededDecisionItems($sources, $directory),
        ];

        usort($items, static function (array $left, array $right): int {
            return [
                $left['failure_code'],
                (string) ($left['reference']['source_id'] ?? ''),
            ] <=> [
                $right['failure_code'],
                (string) ($right['reference']['source_id'] ?? ''),
            ];
        });

        return $items;
    }

    /**
     * Read only currently indexed project-local Obsidian Markdown sources.
     *
     * @return array<string, array{manifest: KnowledgeSourceManifest, content: string}>
     */
    private function currentKnowledgeSources(Project $project, string $directory): array
    {
        $sources = [];

        $manifests = KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->where('source_type', 'obsidian')
            ->whereNull('superseded_at')
            ->orderBy('source_reference')
            ->limit($this->scanLimit())
            ->get();

        foreach ($manifests as $manifest) {
            $reference = $this->normalizeKnowledgeReference(
                (string) $manifest->source_reference,
            );

            if ($reference === null) {
                continue;
            }

            $content = $this->readKnowledgeSource($directory, $reference);

            if ($content === null) {
                continue;
            }

            $sources[$reference] = [
                'manifest' => $manifest,
                'content' => $content,
            ];
        }

        return $sources;
    }

    /**
     * Detect missing STATE.md and the current non-terminal Task brief only when the project vault exists.
     *
     * @return list<DetectorItem>
     */
    private function missingRequiredKnowledgeItems(Project $project, string $directory): array
    {
        $items = [];

        if ($this->safeKnowledgePath($directory, 'STATE.md') === null) {
            $items[] = $this->finding(
                detector: 'missing_required_source',
                failureCode: 'knowledge_gap:missing_state',
                sourceReference: 'STATE.md',
                targetReference: null,
                affectedArea: 'knowledge',
                proposedChange: 'Restore the project-local STATE.md through the normal AIOS knowledge-writing lifecycle so fresh executions can resolve current project state deterministically.',
                occurredAt: now(),
            );
        }

        $task = Task::query()
            ->whereBelongsTo($project)
            ->notCleared()
            ->whereNotIn('status', [
                TaskStatus::Done->value,
                TaskStatus::Cancelled->value,
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->first(['id', 'project_id', 'key', 'title', 'updated_at']);

        if (! $task instanceof Task) {
            return $items;
        }

        $briefReference = 'Task Briefs/'.$task->key.' - '.Str::slug($task->title).'.md';

        if ($this->safeKnowledgePath($directory, $briefReference) === null) {
            $items[] = $this->finding(
                detector: 'missing_required_source',
                failureCode: 'knowledge_gap:missing_task_brief',
                sourceReference: $briefReference,
                targetReference: $task->key,
                affectedArea: 'knowledge/task_brief',
                proposedChange: "Restore the current Task brief for {$task->key} through the normal AIOS task-brief writer before relying on bounded task knowledge retrieval.",
                occurredAt: $task->updated_at,
                evidence: [
                    'task_id' => $task->id,
                    'task_key' => $task->key,
                ],
            );
        }

        return $items;
    }

    /**
     * Detect project-local wiki links whose normalized target cannot be resolved inside the same project vault.
     *
     * @param  array<string, array{manifest: KnowledgeSourceManifest, content: string}>  $sources
     * @return list<DetectorItem>
     */
    private function brokenWikiLinkItems(array $sources, string $directory): array
    {
        $items = [];

        foreach ($sources as $sourceReference => $source) {
            foreach ($this->wikiLinkReferences($source['content']) as $targetReference) {
                if ($this->safeKnowledgePath($directory, $targetReference) !== null) {
                    continue;
                }

                $items[] = $this->finding(
                    detector: 'broken_obsidian_link',
                    failureCode: 'knowledge_gap:broken_obsidian_link',
                    sourceReference: $sourceReference,
                    targetReference: $targetReference,
                    affectedArea: $this->knowledgeArea($sourceReference),
                    proposedChange: "Repair or remove the broken project-local Obsidian link from {$sourceReference} to {$targetReference}; keep the replacement inside this project's bounded knowledge directory.",
                    occurredAt: $this->manifestDate($source['manifest'], 'last_verified_at'),
                    evidence: [
                        'knowledge_source_manifest_id' => $source['manifest']->id,
                    ],
                );
            }
        }

        return $items;
    }

    /**
     * Detect path-shaped repository references that point to removed managed-project files or directories.
     *
     * @param  array<string, array{manifest: KnowledgeSourceManifest, content: string}>  $sources
     * @return list<DetectorItem>
     */
    private function repositoryReferenceItems(Project $project, array $sources): array
    {
        $root = $this->projectRepositoryRoot($project);

        if ($root === null) {
            return [];
        }

        $items = [];

        foreach ($sources as $sourceReference => $source) {
            foreach ($this->repositoryReferences($source['content']) as $targetReference) {
                $state = $this->repositoryReferenceState($root, $targetReference);

                if ($state !== false) {
                    continue;
                }

                $obsoleteImplementationNote = Str::startsWith(
                    $sourceReference,
                    self::ImplementationNotePrefixes,
                );

                $items[] = $this->finding(
                    detector: $obsoleteImplementationNote
                        ? 'obsolete_implementation_note'
                        : 'removed_repository_path',
                    failureCode: $obsoleteImplementationNote
                        ? 'knowledge_gap:obsolete_implementation_note'
                        : 'knowledge_gap:removed_repository_path',
                    sourceReference: $sourceReference,
                    targetReference: $targetReference,
                    affectedArea: $this->knowledgeArea($sourceReference),
                    proposedChange: $obsoleteImplementationNote
                        ? "Review the obsolete implementation note {$sourceReference}; its explicit repository reference {$targetReference} no longer exists in the managed project."
                        : "Update {$sourceReference}; its explicit repository reference {$targetReference} no longer exists in the managed project.",
                    occurredAt: $this->manifestDate($source['manifest'], 'last_verified_at'),
                    evidence: [
                        'knowledge_source_manifest_id' => $source['manifest']->id,
                    ],
                );
            }
        }

        return $items;
    }

    /**
     * Detect non-volatile knowledge source hash transitions already proven by P6-001 manifest supersession.
     *
     * @return list<DetectorItem>
     */
    private function contentHashDriftItems(Project $project): array
    {
        $items = [];

        $manifests = KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->where('source_type', 'obsidian')
            ->whereNotNull('superseded_at')
            ->whereNotNull('superseded_by_id')
            ->with('supersededBy')
            ->orderBy('id')
            ->limit($this->scanLimit())
            ->get();

        foreach ($manifests as $manifest) {
            $reference = $this->normalizeKnowledgeReference(
                (string) $manifest->source_reference,
            );
            $replacement = $manifest->supersededBy;

            if (
                $reference === null
                || ! Str::startsWith($reference, self::StableKnowledgePrefixes)
                || ! $replacement instanceof KnowledgeSourceManifest
                || $replacement->project_id !== $project->id
                || $replacement->source_type !== $manifest->source_type
                || $replacement->source_reference !== $manifest->source_reference
                || hash_equals($manifest->content_hash, $replacement->content_hash)
            ) {
                continue;
            }

            $items[] = $this->finding(
                detector: 'content_hash_drift',
                failureCode: 'knowledge_gap:content_hash_drift',
                sourceReference: $reference,
                targetReference: null,
                affectedArea: $this->knowledgeArea($reference),
                proposedChange: "Verify the intentionality and downstream references of the changed knowledge source {$reference}; P6-001 recorded a new content hash while preserving the superseded version as evidence.",
                occurredAt: $this->manifestDate($manifest, 'superseded_at'),
                identityContext: [
                    'source_type' => $manifest->source_type,
                ],
                evidence: [
                    'superseded_manifest_id' => $manifest->id,
                    'replacement_manifest_id' => $replacement->id,
                    'previous_content_hash' => $manifest->content_hash,
                    'current_content_hash' => $replacement->content_hash,
                ],
                evidenceIdentityContext: [
                    'previous_content_hash' => $manifest->content_hash,
                    'current_content_hash' => $replacement->content_hash,
                ],
            );
        }

        return $items;
    }

    /**
     * Detect explicit approved decision supersession without attempting semantic contradiction analysis.
     *
     * @param  array<string, array{manifest: KnowledgeSourceManifest, content: string}>  $sources
     * @return list<DetectorItem>
     */
    private function supersededDecisionItems(array $sources, string $directory): array
    {
        $items = [];

        foreach ($sources as $sourceReference => $source) {
            if (
                ! Str::startsWith($sourceReference, self::DecisionPrefixes)
                || ! $this->hasApprovedDecisionStatus($source['content'])
            ) {
                continue;
            }

            foreach ($this->explicitSupersededDecisionReferences($source['content']) as $targetReference) {
                if (! Str::startsWith($targetReference, self::DecisionPrefixes)) {
                    continue;
                }

                $targetContent = $this->readKnowledgeSource($directory, $targetReference);

                if (
                    $targetContent === null
                    || ! $this->hasApprovedDecisionStatus($targetContent)
                ) {
                    continue;
                }

                $items[] = $this->finding(
                    detector: 'superseded_approved_decision',
                    failureCode: 'knowledge_gap:superseded_approved_decision',
                    sourceReference: $sourceReference,
                    targetReference: $targetReference,
                    affectedArea: 'knowledge/decisions',
                    proposedChange: "Clarify the historical status of approved decision {$targetReference}; approved decision {$sourceReference} explicitly declares that it supersedes it.",
                    occurredAt: $this->manifestDate($source['manifest'], 'last_verified_at'),
                    evidence: [
                        'knowledge_source_manifest_id' => $source['manifest']->id,
                    ],
                );
            }
        }

        return $items;
    }

    /**
     * Parse wiki-link targets using the same path, heading, alias, and optional-extension semantics as ObsidianProjectNotes.
     *
     * @return list<string>
     */
    private function wikiLinkReferences(string $content): array
    {
        preg_match_all(
            '/\[\[([^\]|#]+)(?:#[^\]|]*)?(?:\|[^\]]*)?\]\]/',
            $content,
            $matches,
        );

        $references = [];

        foreach ($matches[1] as $reference) {
            $normalized = $this->normalizeKnowledgeReference($reference);

            if ($normalized !== null) {
                $references[] = $normalized;
            }
        }

        $references = array_values(array_unique($references));
        sort($references, SORT_STRING);

        return $references;
    }

    /**
     * Parse only exact path-shaped Markdown destinations and inline-code references to allowlisted repository roots.
     *
     * @return list<string>
     */
    private function repositoryReferences(string $content): array
    {
        $candidates = [];

        preg_match_all('/`([^`\r\n]+)`/', $content, $inlineCodeMatches);
        preg_match_all('/\[[^\]\r\n]*\]\(([^)\s]+)\)/', $content, $markdownLinkMatches);

        foreach ([
            ...$inlineCodeMatches[1],
            ...$markdownLinkMatches[1],
        ] as $candidate) {
            $reference = $this->normalizeRepositoryReference($candidate);

            if ($reference !== null) {
                $candidates[] = $reference;
            }
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates, SORT_STRING);

        return $candidates;
    }

    /**
     * Parse explicit `Supersedes: [[...]]` metadata from an approved decision note.
     *
     * @return list<string>
     */
    private function explicitSupersededDecisionReferences(string $content): array
    {
        preg_match_all(
            '/^\s*(?:[-*]\s*)?(?:\*\*)?supersedes(?:\*\*)?\s*:\s*\[\[([^\]|#]+)(?:#[^\]|]*)?(?:\|[^\]]*)?\]\]\s*$/im',
            $content,
            $matches,
        );

        $references = [];

        foreach ($matches[1] as $reference) {
            $normalized = $this->normalizeKnowledgeReference($reference);

            if ($normalized !== null) {
                $references[] = $normalized;
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * Require explicit approved status metadata before treating a decision as approved durable evidence.
     */
    private function hasApprovedDecisionStatus(string $content): bool
    {
        return preg_match(
            '/^\s*(?:[-*]\s*)?(?:\*\*)?(?:decision\s+)?status(?:\*\*)?\s*:\s*(?:\*\*)?approved(?:\*\*)?\s*$/im',
            $content,
        ) === 1;
    }

    /**
     * Normalize one project-root-relative Obsidian Markdown reference and reject boundary escape syntax.
     */
    private function normalizeKnowledgeReference(string $reference): ?string
    {
        $reference = trim($reference);

        if (
            $reference === ''
            || Str::contains($reference, ["\0", '..', '\\'])
            || Str::startsWith($reference, '/')
            || preg_match('/^[A-Za-z]:\//', $reference) === 1
        ) {
            return null;
        }

        $reference = Str::endsWith($reference, '.md')
            ? $reference
            : $reference.'.md';

        return Str::length($reference) <= self::MaxSourceReferenceCharacters
            ? $reference
            : null;
    }

    /**
     * Normalize only allowlisted repository-relative path shapes and reject URLs, traversal, and absolute paths.
     */
    private function normalizeRepositoryReference(string $reference): ?string
    {
        $reference = trim($reference);

        if (
            $reference === ''
            || Str::contains($reference, ["\0", '\\', '://', '?', '#'])
            || Str::startsWith($reference, '/')
            || preg_match('/^[A-Za-z]:\//', $reference) === 1
            || preg_match('/^[A-Za-z0-9._\/-]+$/', $reference) !== 1
        ) {
            return null;
        }

        $segments = explode('/', $reference);

        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }

        $allowlisted = in_array($reference, self::RepositoryRootFiles, true)
            || Str::startsWith($reference, self::RepositoryPathPrefixes);

        return $allowlisted && Str::length($reference) <= self::MaxSourceReferenceCharacters
            ? $reference
            : null;
    }

    /**
     * Return an existing readable project-local knowledge path while enforcing realpath containment.
     */
    private function safeKnowledgePath(string $directory, string $reference): ?string
    {
        $normalized = $this->normalizeKnowledgeReference($reference);

        if ($normalized === null) {
            return null;
        }

        $path = realpath($directory.'/'.$normalized);

        if (
            $path === false
            || ! $this->files->isFile($path)
            || ! $this->isWithin($directory, $path)
        ) {
            return null;
        }

        return $path;
    }

    /**
     * Read a bounded project-local knowledge source only after containment validation.
     */
    private function readKnowledgeSource(string $directory, string $reference): ?string
    {
        $path = $this->safeKnowledgePath($directory, $reference);

        if ($path === null) {
            return null;
        }

        try {
            return $this->files->get($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the configured project-local Obsidian directory without creating missing knowledge paths.
     */
    private function projectKnowledgeDirectory(Project $project): ?string
    {
        $vault = config('aios.obsidian_vault_path');

        if (! is_string($vault) || $vault === '') {
            return null;
        }

        $directory = realpath($vault.'/Projects/'.Str::slug($project->name));

        return $directory !== false
            && $this->files->isDirectory($directory)
            && is_readable($directory)
                ? rtrim($directory, DIRECTORY_SEPARATOR)
                : null;
    }

    /**
     * Resolve the managed project repository root through the existing workspace authority boundary.
     */
    private function projectRepositoryRoot(Project $project): ?string
    {
        try {
            $root = realpath($this->paths->assertProjectPath($project->path));
        } catch (Throwable) {
            return null;
        }

        return $root !== false && is_dir($root)
            ? rtrim($root, DIRECTORY_SEPARATOR)
            : null;
    }

    /**
     * Return true for a safely contained existing reference, false for a missing reference, and null for an unsafe/unverifiable one.
     */
    private function repositoryReferenceState(string $root, string $reference): ?bool
    {
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $reference,
        );

        if (! file_exists($candidate) && ! is_link($candidate)) {
            return false;
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! $this->isWithin($root, $resolved)) {
            return null;
        }

        return is_file($resolved) || is_dir($resolved);
    }

    /**
     * Return a manifest timestamp only when its Eloquent cast resolved to a Carbon value.
     */
    private function manifestDate(
        KnowledgeSourceManifest $manifest,
        string $attribute,
    ): ?CarbonInterface {
        $value = $manifest->getAttribute($attribute);

        return $value instanceof CarbonInterface
            ? $value
            : null;
    }

    /**
     * Build one deterministic point-finding item compatible with the existing KnowledgeImprovementScanner persistence path.
     *
     * @param  array<string, scalar|null>  $identityContext
     * @param  array<string, mixed>  $evidence
     * @param  array<string, scalar|null>  $evidenceIdentityContext
     * @return DetectorItem
     */
    private function finding(
        string $detector,
        string $failureCode,
        ?string $sourceReference,
        ?string $targetReference,
        string $affectedArea,
        string $proposedChange,
        ?CarbonInterface $occurredAt,
        array $identityContext = [],
        array $evidence = [],
        array $evidenceIdentityContext = [],
    ): array {
        $fingerprintPayload = [
            'detector' => $detector,
            'source_reference' => $sourceReference,
            'target_reference' => $targetReference,
            ...$identityContext,
        ];
        $sourceIdentity = [
            'detector' => $detector,
            'source_reference' => $sourceReference,
            'target_reference' => $targetReference,
            ...$evidenceIdentityContext,
        ];

        return [
            'fingerprint_payload' => $fingerprintPayload,
            'source_kind' => 'knowledge_gap',
            'failure_code' => $failureCode,
            'affected_role' => null,
            'affected_area' => $affectedArea,
            'target_type' => KnowledgeImprovementTarget::Documentation,
            'target_skill_slug' => null,
            'proposed_change' => $proposedChange,
            'reference' => [
                'source_type' => 'knowledge_gap',
                'source_id' => $this->stableIdentity($sourceIdentity),
                'detector' => $detector,
                'source_reference' => $sourceReference,
                'target_reference' => $targetReference,
                ...$evidence,
            ],
            'occurred_at' => $occurredAt?->toIso8601String()
                ?? now()->toIso8601String(),
            'minimum_occurrences' => 1,
        ];
    }

    /**
     * Hash bounded normalized identity fields so evidence deduplication never depends on timestamps or machine paths.
     *
     * @param  array<string, scalar|null>  $identity
     */
    private function stableIdentity(array $identity): string
    {
        ksort($identity, SORT_STRING);

        return hash('sha256', json_encode(
            $identity,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Return a stable high-level affected area from a project-local knowledge reference.
     */
    private function knowledgeArea(string $reference): string
    {
        $segments = array_values(array_filter(
            explode('/', $reference),
            static fn (string $segment): bool => $segment !== '',
        ));

        return $segments === [] || count($segments) === 1
            ? 'knowledge'
            : 'knowledge/'.Str::snake(Str::lower($segments[0]));
    }

    /**
     * Verify a resolved path remains strictly below the authorized root.
     */
    private function isWithin(string $root, string $path): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return Str::startsWith($path, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * Bound detector queries with the same configurable scan limit used by the knowledge-improvement scanner.
     */
    private function scanLimit(): int
    {
        return max(
            50,
            min(
                5000,
                (int) config('aios.knowledge_improvement_scan_limit', 500),
            ),
        );
    }
}
