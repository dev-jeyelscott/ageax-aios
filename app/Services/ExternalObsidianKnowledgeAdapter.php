<?php

namespace App\Services;

use App\Models\ExternalKnowledgeSection;
use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Read-only, section-level retrieval of approved external Obsidian knowledge.
 *
 * External knowledge is untrusted advisory context. It is indexed at deterministic heading
 * granularity into a local full-text index and is only ever retrieved through bounded,
 * query-scoped lookups against that index, never by scanning or injecting the vault.
 */
class ExternalObsidianKnowledgeAdapter
{
    private const int MaxSourceReferenceCharacters = 500;

    private const int MaxHeadingCharacters = 500;

    private const int MaxSectionCharacters = 1000;

    private const int MaxQueryTerms = 12;

    private const int MinQueryTermCharacters = 2;

    private const string GlobalScope = 'global';

    private const string ProjectScope = 'project';

    private const string AgentScope = 'agent';

    private const string ActiveStatus = 'active';

    public function __construct(
        private Filesystem $files,
        private KnowledgeSourceManifestSynchronizer $manifests,
    ) {}

    /**
     * Index every approved, active external Markdown source that is scoped to this project.
     *
     * Source notes are only read; provenance is reconciled through the existing knowledge source
     * manifest, so a changed source supersedes its previous version and stops being retrievable.
     *
     * @return array{
     *     indexed_sources: int,
     *     indexed_sections: int,
     *     index_status: string
     * }
     */
    public function indexExternalKnowledge(Project $project): array
    {
        $vaultPath = config('aios.obsidian_vault_path');

        if (! is_string($vaultPath) || $vaultPath === '') {
            return $this->indexEvidence(0, 0, 'vault_unavailable');
        }

        $externalDirectory = $this->resolveExternalKnowledgeDirectory($vaultPath);

        if ($externalDirectory === null) {
            return $this->indexEvidence(0, 0, 'external_knowledge_unavailable');
        }

        try {
            $files = $this->files->allFiles($externalDirectory);
        } catch (Throwable) {
            return $this->indexEvidence(0, 0, 'file_enumeration_failed');
        }

        $candidates = [];

        foreach ($files as $file) {
            if (Str::lower($file->getExtension()) !== 'md') {
                continue;
            }

            $realPath = $file->getRealPath();

            if (! is_string($realPath) || ! $this->isWithin($externalDirectory, $realPath)) {
                continue;
            }

            $reference = $this->normalizeReference($file->getRelativePathname());

            if ($reference === null) {
                continue;
            }

            $candidates[$reference] = $realPath;
        }

        ksort($candidates);

        $indexedSources = 0;
        $indexedSections = 0;
        $currentManifestIds = [];

        try {
            foreach ($candidates as $reference => $realPath) {
                $content = $this->files->get($realPath);
                $document = $this->extractFrontmatterAndContent($content);

                if ($document === null) {
                    continue;
                }

                $eligibility = $this->resolveEligibility($document['frontmatter'], $project);

                if ($eligibility === null) {
                    continue;
                }

                $manifest = $this->manifests->observeExternalObsidianSource(
                    $project,
                    $reference,
                    $content,
                );

                if (! $manifest instanceof KnowledgeSourceManifest) {
                    continue;
                }

                $currentManifestIds[] = $manifest->id;
                $indexedSources++;
                $indexedSections += $this->replaceIndexedSections(
                    $project,
                    $manifest,
                    $reference,
                    $eligibility,
                    $document['content'],
                );
            }
        } catch (Throwable) {
            return $this->indexEvidence($indexedSources, $indexedSections, 'index_write_failed');
        }

        $this->purgeStaleSections($project, $currentManifestIds);

        return $this->indexEvidence($indexedSources, $indexedSections, 'success');
    }

    /**
     * Retrieve bounded, query-matched external knowledge sections for a resolved identity.
     *
     * Only current (non-superseded) indexed versions whose scope matches the resolved project and
     * optional logical Agent are eligible; unrelated indexed knowledge is never returned.
     *
     * @return array{
     *     sections: list<array{
     *         source_reference: string,
     *         heading: string,
     *         level: int,
     *         scope: string,
     *         content: string,
     *         character_count: int,
     *         content_hash: string,
     *         knowledge_source_manifest_id: int,
     *         matched_terms: list<string>
     *     }>,
     *     total_character_count: int,
     *     query_terms: list<string>,
     *     retrieval_status: string
     * }
     */
    public function retrieveKnowledge(
        Project $project,
        string $query,
        ?int $agentId = null,
        int $maxCharacters = 5000,
        int $maxSections = 10,
    ): array {
        $terms = $this->queryTerms($query);

        if ($terms === []) {
            return $this->retrievalEvidence([], [], 'query_required');
        }

        try {
            $matches = $this->eligibleSections($project, $agentId)
                ->where(function (Builder $builder) use ($terms): void {
                    foreach ($terms as $term) {
                        $builder->orWhere('search_text', 'like', '%'.$term.'%');
                    }
                })
                ->orderBy('source_reference')
                ->orderBy('position')
                ->orderBy('id')
                ->get();
        } catch (Throwable) {
            return $this->retrievalEvidence([], $terms, 'index_unavailable');
        }

        $ranked = [];

        foreach ($matches as $index => $section) {
            $matchedTerms = array_values(array_filter(
                $terms,
                fn (string $term): bool => Str::contains($section->search_text, $term),
            ));

            if ($matchedTerms === []) {
                continue;
            }

            $ranked[] = [
                'score' => count($matchedTerms),
                'order' => $index,
                'section' => $section,
                'matched_terms' => $matchedTerms,
            ];
        }

        usort(
            $ranked,
            fn (array $left, array $right): int => $right['score'] <=> $left['score']
                ?: $left['order'] <=> $right['order'],
        );

        $sections = [];
        $remainingCharacters = max($maxCharacters, 0);

        foreach ($ranked as $match) {
            if (count($sections) >= $maxSections || $remainingCharacters <= 0) {
                break;
            }

            $section = $match['section'];
            $excerpt = Str::substr(
                $section->content,
                0,
                min(self::MaxSectionCharacters, $remainingCharacters),
            );
            $characterCount = Str::length($excerpt);

            if ($characterCount === 0) {
                continue;
            }

            $sections[] = [
                'source_reference' => $section->source_reference,
                'heading' => $section->heading,
                'level' => $section->heading_level,
                'scope' => $section->scope,
                'content' => $excerpt,
                'character_count' => $characterCount,
                'content_hash' => $section->content_hash,
                'knowledge_source_manifest_id' => $section->knowledge_source_manifest_id,
                'matched_terms' => $match['matched_terms'],
            ];

            $remainingCharacters -= $characterCount;
        }

        return $this->retrievalEvidence($sections, $terms, 'success');
    }

    /**
     * Constrain the index to current versions the resolved identity is allowed to read.
     *
     * @return Builder<ExternalKnowledgeSection>
     */
    private function eligibleSections(Project $project, ?int $agentId): Builder
    {
        return ExternalKnowledgeSection::query()
            ->whereBelongsTo($project)
            ->whereHas(
                'knowledgeSourceManifest',
                fn (Builder $builder) => $builder->whereNull('superseded_at'),
            )
            ->where(function (Builder $builder) use ($project, $agentId): void {
                $builder
                    ->where('scope', self::GlobalScope)
                    ->orWhere(
                        fn (Builder $scoped) => $scoped
                            ->where('scope', self::ProjectScope)
                            ->where('project_id', $project->id),
                    );

                if ($agentId !== null) {
                    $builder->orWhere(
                        fn (Builder $scoped) => $scoped
                            ->where('scope', self::AgentScope)
                            ->where('project_id', $project->id)
                            ->where('scoped_agent_id', $agentId),
                    );
                }
            });
    }

    /**
     * Replace the indexed sections of one observed source version idempotently.
     *
     * @param  array{scope: string, scoped_agent_id: int|null}  $eligibility
     */
    private function replaceIndexedSections(
        Project $project,
        KnowledgeSourceManifest $manifest,
        string $sourceReference,
        array $eligibility,
        string $content,
    ): int {
        $sections = $this->parseMarkdownSections($content);
        $indexedAt = now();

        return DB::transaction(function () use (
            $project,
            $manifest,
            $sourceReference,
            $eligibility,
            $sections,
            $indexedAt,
        ): int {
            ExternalKnowledgeSection::query()
                ->where('knowledge_source_manifest_id', $manifest->id)
                ->delete();

            foreach ($sections as $position => $section) {
                ExternalKnowledgeSection::query()->create([
                    'project_id' => $project->id,
                    'knowledge_source_manifest_id' => $manifest->id,
                    'source_reference' => $sourceReference,
                    'scope' => $eligibility['scope'],
                    'scoped_agent_id' => $eligibility['scoped_agent_id'],
                    'heading' => $section['heading'],
                    'heading_level' => $section['level'],
                    'position' => $position,
                    'content' => $section['content'],
                    'search_text' => $this->searchText($section['heading'], $section['content']),
                    'character_count' => Str::length($section['content']),
                    'content_hash' => $manifest->content_hash,
                    'indexed_at' => $indexedAt,
                ]);
            }

            return count($sections);
        });
    }

    /**
     * Drop indexed sections that are no longer backed by a current eligible source version.
     *
     * @param  list<int>  $currentManifestIds
     */
    private function purgeStaleSections(Project $project, array $currentManifestIds): void
    {
        ExternalKnowledgeSection::query()
            ->whereBelongsTo($project)
            ->when(
                $currentManifestIds !== [],
                fn (Builder $builder) => $builder->whereNotIn(
                    'knowledge_source_manifest_id',
                    $currentManifestIds,
                ),
            )
            ->delete();
    }

    /**
     * Parse Markdown content into deterministic heading-level sections.
     *
     * @return list<array{heading: string, level: int, content: string}>
     */
    private function parseMarkdownSections(string $content): array
    {
        $sections = [];
        $currentSection = null;
        $currentContent = [];

        foreach (explode("\n", $content) as $line) {
            $headingMatch = [];

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $headingMatch)) {
                $sections = $this->appendSection($sections, $currentSection, $currentContent);

                $currentSection = [
                    'heading' => Str::substr(trim($headingMatch[2]), 0, self::MaxHeadingCharacters),
                    'level' => Str::length($headingMatch[1]),
                ];
                $currentContent = [];

                continue;
            }

            $currentContent[] = $line;
        }

        return $this->appendSection($sections, $currentSection, $currentContent);
    }

    /**
     * Append one completed section when it has both a heading and content.
     *
     * @param  list<array{heading: string, level: int, content: string}>  $sections
     * @param  array{heading: string, level: int}|null  $currentSection
     * @param  list<string>  $currentContent
     * @return list<array{heading: string, level: int, content: string}>
     */
    private function appendSection(array $sections, ?array $currentSection, array $currentContent): array
    {
        if ($currentSection === null) {
            return $sections;
        }

        $sectionContent = trim(implode("\n", $currentContent));

        if (Str::length($sectionContent) === 0) {
            return $sections;
        }

        $sections[] = [
            'heading' => $currentSection['heading'],
            'level' => $currentSection['level'],
            'content' => Str::substr($sectionContent, 0, self::MaxSectionCharacters),
        ];

        return $sections;
    }

    /**
     * Extract YAML frontmatter and Markdown content from source.
     *
     * @return array{frontmatter: array<string, mixed>, content: string}|null
     */
    private function extractFrontmatterAndContent(string $source): ?array
    {
        $frontmatterMatch = [];

        if (! preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/ms', $source, $frontmatterMatch)) {
            return null;
        }

        try {
            $frontmatter = Yaml::parse($frontmatterMatch[1]);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($frontmatter)) {
            return null;
        }

        return [
            'frontmatter' => $frontmatter,
            'content' => $frontmatterMatch[2],
        ];
    }

    /**
     * Resolve indexable scope for a source, failing closed on absent or invalid approval metadata.
     *
     * @param  array<string, mixed>  $frontmatter
     * @return array{scope: string, scoped_agent_id: int|null}|null
     */
    private function resolveEligibility(array $frontmatter, Project $project): ?array
    {
        if (! $this->isApprovedAndActive($frontmatter)) {
            return null;
        }

        $scope = $frontmatter['scope'] ?? null;

        if (! is_string($scope)) {
            return null;
        }

        $scope = Str::lower(trim($scope));
        $sourceProjectId = $this->positiveInteger($frontmatter['project_id'] ?? null);
        $sourceAgentId = $this->positiveInteger($frontmatter['agent_id'] ?? null);

        if ($scope === self::GlobalScope) {
            return ['scope' => self::GlobalScope, 'scoped_agent_id' => null];
        }

        if ($scope === self::ProjectScope) {
            return $sourceProjectId === $project->id
                ? ['scope' => self::ProjectScope, 'scoped_agent_id' => null]
                : null;
        }

        if ($scope === self::AgentScope) {
            return $sourceProjectId === $project->id && $sourceAgentId !== null
                ? ['scope' => self::AgentScope, 'scoped_agent_id' => $sourceAgentId]
                : null;
        }

        return null;
    }

    /**
     * Require explicit approval and an explicit active lifecycle status.
     *
     * @param  array<string, mixed>  $frontmatter
     */
    private function isApprovedAndActive(array $frontmatter): bool
    {
        $approved = $frontmatter['approved'] ?? null;
        $status = $frontmatter['status'] ?? null;

        if ($approved !== true || ! is_string($status)) {
            return false;
        }

        return Str::lower(trim($status)) === self::ActiveStatus;
    }

    /**
     * Coerce a frontmatter identifier without accepting loose or non-numeric values.
     */
    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    /**
     * Build the deterministic lowercase full-text representation of one section.
     */
    private function searchText(string $heading, string $content): string
    {
        $text = Str::lower($heading."\n".$content);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Normalize a retrieval query into bounded deterministic full-text terms.
     *
     * @return list<string>
     */
    private function queryTerms(string $query): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', Str::lower(trim($query))) ?: [];

        $terms = array_values(array_unique(array_filter(
            $tokens,
            fn (string $token): bool => Str::length($token) >= self::MinQueryTermCharacters,
        )));

        return array_slice($terms, 0, self::MaxQueryTerms);
    }

    /**
     * Resolve the external knowledge directory without creating or traversing the vault.
     */
    private function resolveExternalKnowledgeDirectory(string $vaultPath): ?string
    {
        $directory = realpath($vaultPath.'/External Knowledge');

        if (
            $directory === false
            || ! is_dir($directory)
            || ! is_readable($directory)
        ) {
            return null;
        }

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /**
     * Normalize a stable reference and reject traversal or absolute paths.
     */
    private function normalizeReference(string $reference): ?string
    {
        $reference = str_replace('\\', '/', trim($reference));

        if (
            $reference === ''
            || Str::contains($reference, "\0")
            || Str::startsWith($reference, '/')
            || preg_match('/^[A-Za-z]:\//', $reference) === 1
        ) {
            return null;
        }

        $segments = explode('/', $reference);

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        $segments = array_values(array_filter(
            $segments,
            fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            return null;
        }

        $normalized = implode('/', $segments);

        return Str::length($normalized) <= self::MaxSourceReferenceCharacters
            ? $normalized
            : null;
    }

    /**
     * Verify a resolved source file remains strictly below its authorized root.
     */
    private function isWithin(string $root, string $path): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return Str::startsWith(
            $path,
            $root.DIRECTORY_SEPARATOR,
        );
    }

    /**
     * Shape bounded indexing evidence.
     *
     * @return array{indexed_sources: int, indexed_sections: int, index_status: string}
     */
    private function indexEvidence(int $sources, int $sections, string $status): array
    {
        return [
            'indexed_sources' => $sources,
            'indexed_sections' => $sections,
            'index_status' => $status,
        ];
    }

    /**
     * Shape bounded retrieval evidence.
     *
     * @param  list<array{
     *     source_reference: string,
     *     heading: string,
     *     level: int,
     *     scope: string,
     *     content: string,
     *     character_count: int,
     *     content_hash: string,
     *     knowledge_source_manifest_id: int,
     *     matched_terms: list<string>
     * }>  $sections
     * @param  list<string>  $terms
     * @return array{
     *     sections: list<array{
     *         source_reference: string,
     *         heading: string,
     *         level: int,
     *         scope: string,
     *         content: string,
     *         character_count: int,
     *         content_hash: string,
     *         knowledge_source_manifest_id: int,
     *         matched_terms: list<string>
     *     }>,
     *     total_character_count: int,
     *     query_terms: list<string>,
     *     retrieval_status: string
     * }
     */
    private function retrievalEvidence(array $sections, array $terms, string $status): array
    {
        return [
            'sections' => $sections,
            'total_character_count' => array_sum(array_column($sections, 'character_count')),
            'query_terms' => $terms,
            'retrieval_status' => $status,
        ];
    }
}
