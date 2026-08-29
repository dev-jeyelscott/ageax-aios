<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class ExternalObsidianKnowledgeAdapter
{
    private const int MaxSourceReferenceCharacters = 500;

    private const int MaxContentCharacters = 10000;

    private const string GlobalScope = 'global';

    private const string ProjectScope = 'project';

    private const string AgentScope = 'agent';

    public function __construct(private Filesystem $files) {}

    /**
     * Retrieve sections from external Markdown sources with validated YAML frontmatter scopes.
     * Returns only approved knowledge whose scope, project, and optional Agent match the request.
     *
     * @return array{
     *     sections: list<array{
     *         source_reference: string,
     *         heading: string,
     *         level: int,
     *         content: string,
     *         character_count: int
     *     }>,
     *     total_character_count: int,
     *     retrieval_status: string
     * }
     */
    public function retrieveKnowledge(
        Project $project,
        ?int $agentId = null,
        int $maxCharacters = 5000,
    ): array {
        $vaultPath = config('aios.obsidian_vault_path');

        if (! is_string($vaultPath) || $vaultPath === '') {
            return [
                'sections' => [],
                'total_character_count' => 0,
                'retrieval_status' => 'vault_unavailable',
            ];
        }

        $externalDirectory = $this->resolveExternalKnowledgeDirectory($vaultPath);

        if (! is_dir($externalDirectory) || ! is_readable($externalDirectory)) {
            return [
                'sections' => [],
                'total_character_count' => 0,
                'retrieval_status' => 'external_knowledge_unavailable',
            ];
        }

        $sections = [];
        $remainingCharacters = $maxCharacters;

        try {
            $files = $this->files->allFiles($externalDirectory);
        } catch (Throwable) {
            return [
                'sections' => [],
                'total_character_count' => 0,
                'retrieval_status' => 'file_enumeration_failed',
            ];
        }

        foreach ($files as $file) {
            if ($remainingCharacters <= 0) {
                break;
            }

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

            try {
                $content = $this->files->get($realPath);
            } catch (Throwable) {
                continue;
            }

            $extracted = $this->extractFrontmatterAndContent($content);

            if ($extracted === null || ! $this->validateFrontmatter($extracted['frontmatter'], $project, $agentId)) {
                continue;
            }

            $fileSections = $this->parseMarkdownSections(
                $extracted['content'],
                $reference,
                $remainingCharacters,
            );

            foreach ($fileSections as $section) {
                $characterCount = Str::length($section['content']);
                $sections[] = $section;
                $remainingCharacters -= $characterCount;

                if ($remainingCharacters <= 0) {
                    break;
                }
            }
        }

        $totalCharacters = array_sum(array_column($sections, 'character_count'));

        return [
            'sections' => $sections,
            'total_character_count' => $totalCharacters,
            'retrieval_status' => 'success',
        ];
    }

    /**
     * Parse Markdown content into deterministic section-level units.
     *
     * @return list<array{
     *     source_reference: string,
     *     heading: string,
     *     level: int,
     *     content: string,
     *     character_count: int
     * }>
     */
    private function parseMarkdownSections(
        string $content,
        string $sourceReference,
        int $maxCharacters,
    ): array {
        $sections = [];
        $currentCharacters = 0;

        if (Str::length($content) === 0) {
            return [];
        }

        $lines = explode("\n", $content);
        $currentSection = null;
        $currentContent = [];

        foreach ($lines as $line) {
            if ($currentCharacters >= $maxCharacters) {
                break;
            }

            $headingMatch = [];

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $headingMatch)) {
                if ($currentSection !== null) {
                    $sectionContent = trim(implode("\n", $currentContent));

                    if (Str::length($sectionContent) > 0) {
                        $characterCount = min(
                            Str::length($sectionContent),
                            $maxCharacters - $currentCharacters,
                        );
                        $truncated = Str::substr($sectionContent, 0, $characterCount);

                        $sections[] = [
                            'source_reference' => $sourceReference,
                            'heading' => $currentSection['heading'],
                            'level' => $currentSection['level'],
                            'content' => $truncated,
                            'character_count' => $characterCount,
                        ];

                        $currentCharacters += $characterCount;
                    }
                }

                $level = Str::length($headingMatch[1]);
                $heading = trim($headingMatch[2]);

                $currentSection = [
                    'heading' => $heading,
                    'level' => $level,
                ];
                $currentContent = [];
            } else {
                $currentContent[] = $line;
            }
        }

        if ($currentSection !== null && $currentCharacters < $maxCharacters) {
            $sectionContent = trim(implode("\n", $currentContent));

            if (Str::length($sectionContent) > 0) {
                $characterCount = min(
                    Str::length($sectionContent),
                    $maxCharacters - $currentCharacters,
                );
                $truncated = Str::substr($sectionContent, 0, $characterCount);

                $sections[] = [
                    'source_reference' => $sourceReference,
                    'heading' => $currentSection['heading'],
                    'level' => $currentSection['level'],
                    'content' => $truncated,
                    'character_count' => $characterCount,
                ];
            }
        }

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

            if (! is_array($frontmatter)) {
                $frontmatter = [];
            }
        } catch (Throwable) {
            return null;
        }

        $content = $frontmatterMatch[2];

        return [
            'frontmatter' => $frontmatter,
            'content' => $content,
        ];
    }

    /**
     * Validate YAML frontmatter scope, project, and optional Agent constraints.
     */
    private function validateFrontmatter(array $frontmatter, Project $project, ?int $agentId): bool
    {
        $scope = $frontmatter['scope'] ?? null;
        $projectId = $frontmatter['project_id'] ?? null;
        $sourceAgentId = $frontmatter['agent_id'] ?? null;

        if (! is_string($scope) || ! in_array($scope, [self::GlobalScope, self::ProjectScope, self::AgentScope], true)) {
            return false;
        }

        if ($scope === self::GlobalScope) {
            return true;
        }

        if ($scope === self::ProjectScope) {
            return $projectId === $project->id || (int) $projectId === $project->id;
        }

        if ($scope === self::AgentScope) {
            if ($agentId === null) {
                return false;
            }

            return ((int) $sourceAgentId === $agentId) && ((int) $projectId === $project->id);
        }

        return false;
    }

    /**
     * Resolve the external knowledge directory without creating it.
     */
    private function resolveExternalKnowledgeDirectory(string $vaultPath): string
    {
        return $vaultPath.'/External Knowledge';
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
}
