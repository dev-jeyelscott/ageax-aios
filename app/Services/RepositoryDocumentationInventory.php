<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Select the small, authoritative repository-document set used by reconciliation.
 *
 * This is deliberately an allow-list, not a repository Markdown crawler. Content remains in
 * Git; the manifest boundary stores only hashes and clean-HEAD attribution.
 */
class RepositoryDocumentationInventory
{
    private const int MaxFiles = 120;

    private const array RootDocuments = ['AGENTS.md', 'CLAUDE.md', 'MASTER-PROMPT.md'];

    private const array ApprovedDirectories = ['docs', 'architecture', 'specs', 'specifications', 'adr'];

    public function __construct(
        private Filesystem $files,
        private WorkspacePathResolver $paths,
        private KnowledgeSourceManifestSynchronizer $manifests,
    ) {}

    /**
     * @param  list<array{classification: string, path: string}>  $committedChanges
     * @return list<array{path: string, content_hash: string, git_sha: ?string, category: string}>
     */
    public function synchronize(Project $project, array $committedChanges): array
    {
        $paths = $this->paths($project, $committedChanges);
        $items = [];

        foreach ($paths as $path => $category) {
            $manifest = $this->manifests->trackRepositoryFile($project, $path);

            if ($manifest === null) {
                continue;
            }

            $items[] = [
                'path' => $path,
                'content_hash' => $manifest->content_hash,
                'git_sha' => $manifest->git_sha,
                'category' => $category,
            ];
        }

        return $items;
    }

    /**
     * @param  list<array{classification: string, path: string}>  $committedChanges
     * @return array<string, string>
     */
    private function paths(Project $project, array $committedChanges): array
    {
        $projectPath = $this->paths->assertProjectPath($project->path);
        $selected = [];

        foreach (self::RootDocuments as $path) {
            $selected[$path] = 'governance';
        }

        $selected['.ai/rules/index.md'] = 'rule_index';
        $this->addMarkdownDirectory($projectPath, '.ai/rules', 'rule', $selected);

        foreach (self::ApprovedDirectories as $directory) {
            $this->addMarkdownDirectory($projectPath, $directory, 'approved_documentation', $selected);
        }

        $uiRelevant = collect($committedChanges)->contains(
            fn (array $change): bool => Str::startsWith($change['path'], ['resources/js/', 'resources/css/', 'design-system.html'])
        ) || array_key_exists('design-system.html', $selected);

        foreach ($committedChanges as $change) {
            $path = $change['path'];

            if (Str::endsWith(Str::lower($path), '.md')) {
                $selected[$path] ??= 'changed_relevant_documentation';
            }
        }

        if ($uiRelevant && is_file($projectPath.'/design-system.html')) {
            $selected['design-system.html'] = 'ui_documentation';
        }

        ksort($selected, SORT_STRING);

        return array_slice($selected, 0, self::MaxFiles, true);
    }

    /** @param array<string, string> $selected */
    private function addMarkdownDirectory(string $projectPath, string $relativeDirectory, string $category, array &$selected): void
    {
        $directory = $projectPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (! is_dir($directory)) {
            return;
        }

        try {
            foreach ($this->files->allFiles($directory) as $file) {
                if (count($selected) >= self::MaxFiles || Str::lower($file->getExtension()) !== 'md') {
                    continue;
                }

                $selected[$relativeDirectory.'/'.$file->getRelativePathname()] = $category;
            }
        } catch (Throwable) {
            // A missing or unreadable optional documentation directory is not a repository failure.
        }
    }
}
