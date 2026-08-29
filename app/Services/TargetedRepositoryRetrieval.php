<?php

namespace App\Services;

use App\Concerns\RejectsSecretMaterial;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

class TargetedRepositoryRetrieval
{
    use RejectsSecretMaterial;

    private const int MaxFiles = 100;

    private const int MaxExcerptLines = 40;

    private const int MaxExcerptCharacters = 2000;

    private const int MaxSymbols = 20;

    private const int MaxSymbolScanBytes = 262144;

    private const array ExcludedFiles = ['.env', '.env.local', '.env.example'];

    private const array ExcludedDirectories = ['.git', '.github', 'vendor', 'node_modules', '.pytest_cache', 'build', 'dist'];

    public function __construct(
        private Filesystem $files,
        private WorkspacePathResolver $paths,
        private ProjectGitState $git,
    ) {}

    /**
     * Retrieve targeted repository files based on task context, explicit paths, and Git metadata.
     *
     * @param  array<string, mixed>  $discoveryInputs
     * @return array{
     *     files: list<array{
     *         path: string,
     *         reason: string,
     *         git_sha: ?string,
     *         excerpt: string,
     *         excerpt_line_count: int,
     *         excerpt_truncated: bool,
     *         symbols: list<string>,
     *     }>,
     *     repository_revision: ?string,
     *     repository_state: array{state: string, clean: bool, head_sha: ?string, base_sha: ?string},
     *     selection_reason: string,
     * }
     */
    public function retrieve(Project $project, array $discoveryInputs = []): array
    {
        $projectPath = $this->paths->assertProjectPath($project->path);

        if (! is_dir($projectPath)) {
            return $this->emptyResult('The project directory does not exist.');
        }

        $gitState = $this->git->inspect($projectPath);

        if (! $gitState['inspectable']) {
            return $this->emptyResult('The project directory is not inspectable as a Git repository.');
        }

        $repositoryRoot = realpath($projectPath);

        if (! is_string($repositoryRoot)) {
            return $this->emptyResult('The project directory could not be resolved to a real path.');
        }

        $selected = [];

        if (isset($discoveryInputs['explicit_paths']) && is_array($discoveryInputs['explicit_paths'])) {
            foreach ($discoveryInputs['explicit_paths'] as $path) {
                $this->selectReferencedPath($path, 'explicit_path_from_discovery_input', $selected);
            }
        }

        if (isset($discoveryInputs['changed_files']) && is_array($discoveryInputs['changed_files'])) {
            foreach ($discoveryInputs['changed_files'] as $path) {
                $this->selectReferencedPath($path, 'changed_file_from_git_metadata', $selected);
            }
        }

        $searchDirectories = isset($discoveryInputs['default_search_dirs']) && is_array($discoveryInputs['default_search_dirs'])
            ? $this->resolveSearchDirectories($repositoryRoot, $discoveryInputs['default_search_dirs'])
            : [];

        if (isset($discoveryInputs['task_terms']) && is_array($discoveryInputs['task_terms'])) {
            $termDirectories = $searchDirectories === []
                ? [$repositoryRoot]
                : $searchDirectories;

            $this->selectByTermsInDirs($repositoryRoot, $discoveryInputs['task_terms'], $termDirectories, $selected);
        }

        if ($selected === [] && $searchDirectories !== []) {
            foreach ($searchDirectories as $directory) {
                $this->addMarkdownFiles($repositoryRoot, $directory, $selected);
            }
        }

        $repositoryState = $this->repositoryState($gitState);
        $files = [];

        foreach ($selected as $path => $reason) {
            if (count($files) >= self::MaxFiles) {
                break;
            }

            $resolved = $this->resolvePath($repositoryRoot, $path);

            if ($resolved === null || ! is_file($resolved) || ! $this->isWithin($repositoryRoot, $resolved)) {
                continue;
            }

            if (! $this->isSafeFile($resolved)) {
                continue;
            }

            try {
                $content = $this->files->get($resolved);
            } catch (Throwable) {
                continue;
            }

            if ($this->containsSecretMaterial($content)) {
                continue;
            }

            $excerpt = $this->excerpt($content);

            $files[] = [
                'path' => $this->relativePath($repositoryRoot, $resolved),
                'reason' => $reason,
                'git_sha' => $gitState['head_sha'],
                'excerpt' => $excerpt['text'],
                'excerpt_line_count' => $excerpt['line_count'],
                'excerpt_truncated' => $excerpt['truncated'],
                'symbols' => $this->symbols($resolved, $content),
            ];
        }

        return [
            'files' => $files,
            'repository_revision' => $gitState['head_sha'],
            'repository_state' => $repositoryState,
            'selection_reason' => 'deterministic_targeted_discovery_from_task_terms_and_changed_files',
        ];
    }

    /**
     * @param  array{clean: bool, head_sha: ?string, base_sha: ?string}  $gitState
     * @return array{state: string, clean: bool, head_sha: ?string, base_sha: ?string}
     */
    private function repositoryState(array $gitState): array
    {
        return [
            'state' => $gitState['clean'] ? 'clean' : 'dirty',
            'clean' => $gitState['clean'],
            'head_sha' => $gitState['head_sha'],
            'base_sha' => $gitState['base_sha'],
        ];
    }

    /** @param  array<string, string>  $selected */
    private function selectReferencedPath(mixed $path, string $reason, array &$selected): void
    {
        if (! is_string($path)) {
            return;
        }

        $normalized = $this->normalizePath($path);

        if ($normalized === null) {
            return;
        }

        $selected[$normalized] ??= $reason;
    }

    /**
     * Resolve caller-provided search directories against the approved repository root before any
     * directory is inspected, so traversal, absolute paths, and symlink escapes never reach the
     * filesystem walk.
     *
     * @param  array<int, mixed>  $directories
     * @return list<string>
     */
    private function resolveSearchDirectories(string $repositoryRoot, array $directories): array
    {
        $resolved = [];

        foreach ($directories as $directory) {
            if (! is_string($directory)) {
                continue;
            }

            $candidate = $this->resolveDirectory($repositoryRoot, $directory);

            if ($candidate !== null && ! in_array($candidate, $resolved, true)) {
                $resolved[] = $candidate;
            }
        }

        return $resolved;
    }

    private function resolveDirectory(string $repositoryRoot, string $directory): ?string
    {
        $directory = trim(str_replace('\\', '/', trim($directory)), '/');

        if ($directory === '' || $directory === '.') {
            return $repositoryRoot;
        }

        $normalized = $this->normalizePath($directory);

        if ($normalized === null) {
            return null;
        }

        $resolved = realpath($repositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized));

        if (! is_string($resolved) || ! is_dir($resolved) || ! $this->isWithin($repositoryRoot, $resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * @param  array<int, mixed>  $terms
     * @param  list<string>  $directories
     * @param  array<string, string>  $selected
     */
    private function selectByTermsInDirs(string $repositoryRoot, array $terms, array $directories, array &$selected): void
    {
        foreach ($directories as $directory) {
            foreach ($terms as $term) {
                if (! is_string($term) || trim($term) === '') {
                    continue;
                }

                $this->searchDirectory($repositoryRoot, $directory, $term, $selected);
            }
        }
    }

    /** @param  array<string, string>  $selected */
    private function searchDirectory(string $repositoryRoot, string $directory, string $term, array &$selected, int $depth = 0): void
    {
        if ($depth > 3 || count($selected) >= self::MaxFiles) {
            return;
        }

        try {
            foreach ($this->files->directories($directory) as $subdirectory) {
                $resolved = $this->containedRealPath($repositoryRoot, $subdirectory);

                if ($resolved === null || $this->isExcludedDirectory($resolved)) {
                    continue;
                }

                $this->searchDirectory($repositoryRoot, $resolved, $term, $selected, $depth + 1);
            }

            foreach ($this->files->files($directory) as $file) {
                if (count($selected) >= self::MaxFiles) {
                    break;
                }

                if (Str::lower($file->getExtension()) !== 'md') {
                    continue;
                }

                if (! Str::contains(Str::lower($file->getFilename()), Str::lower($term))) {
                    continue;
                }

                $resolved = $this->containedRealPath($repositoryRoot, $file->getPathname());

                if ($resolved === null) {
                    continue;
                }

                $selected[$this->relativePath($repositoryRoot, $resolved)] ??= 'matched_task_term_in_filename';
            }
        } catch (Throwable) {
            // Unreadable directory, skip
        }
    }

    /** @param  array<string, string>  $selected */
    private function addMarkdownFiles(string $repositoryRoot, string $directory, array &$selected): void
    {
        try {
            foreach ($this->files->allFiles($directory) as $file) {
                if (count($selected) >= self::MaxFiles) {
                    break;
                }

                if (Str::lower($file->getExtension()) !== 'md') {
                    continue;
                }

                $resolved = $this->containedRealPath($repositoryRoot, $file->getPathname());

                if ($resolved === null) {
                    continue;
                }

                $selected[$this->relativePath($repositoryRoot, $resolved)] ??= 'found_in_default_directory';
            }
        } catch (Throwable) {
            // Unreadable directory, skip
        }
    }

    /**
     * Bounded excerpt derived from already secret-screened content.
     *
     * @return array{text: string, line_count: int, truncated: bool}
     */
    private function excerpt(string $content): array
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            return ['text' => '', 'line_count' => 0, 'truncated' => true];
        }

        $lines = preg_split('/\R/', $content) ?: [];
        $truncated = count($lines) > self::MaxExcerptLines;
        $text = implode("\n", array_slice($lines, 0, self::MaxExcerptLines));

        if (mb_strlen($text) > self::MaxExcerptCharacters) {
            $text = mb_substr($text, 0, self::MaxExcerptCharacters);
            $truncated = true;
        }

        return [
            'text' => $text,
            'line_count' => $text === '' ? 0 : count(explode("\n", $text)),
            'truncated' => $truncated,
        ];
    }

    /**
     * Lightweight deterministic symbol support for the file types AIOS already reasons about.
     *
     * @return list<string>
     */
    private function symbols(string $path, string $content): array
    {
        if (strlen($content) > self::MaxSymbolScanBytes || ! mb_check_encoding($content, 'UTF-8')) {
            return [];
        }

        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        $pattern = match ($extension) {
            'php' => '/^\s*(?:abstract\s+|final\s+|readonly\s+)*(class|interface|trait|enum|function)\s+([A-Za-z_][A-Za-z0-9_]*)/m',
            'md' => '/^(#{1,6})\s+(.+?)\s*$/m',
            default => null,
        };

        if ($pattern === null || preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $symbols = [];

        foreach ($matches as $match) {
            $symbol = $extension === 'php'
                ? $match[1].' '.$match[2]
                : 'heading '.trim($match[2]);

            if (! in_array($symbol, $symbols, true)) {
                $symbols[] = $symbol;
            }

            if (count($symbols) >= self::MaxSymbols) {
                break;
            }
        }

        return $symbols;
    }

    private function containedRealPath(string $repositoryRoot, string $path): ?string
    {
        $resolved = realpath($path);

        if (! is_string($resolved) || ! $this->isWithin($repositoryRoot, $resolved)) {
            return null;
        }

        return $resolved;
    }

    private function resolvePath(string $repositoryRoot, string $path): ?string
    {
        $resolved = realpath($repositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));

        return is_string($resolved) ? $resolved : null;
    }

    private function relativePath(string $repositoryRoot, string $resolved): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($repositoryRoot) + 1));
    }

    private function normalizePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if (
            $path === ''
            || Str::contains($path, "\0")
            || Str::startsWith($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1
        ) {
            return null;
        }

        $segments = explode('/', $path);

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

        return implode('/', $segments);
    }

    private function isSafeFile(string $path): bool
    {
        $filename = basename($path);

        if (in_array($filename, self::ExcludedFiles, true)) {
            return false;
        }

        foreach (self::ExcludedDirectories as $excluded) {
            if (Str::contains($path, DIRECTORY_SEPARATOR.$excluded.DIRECTORY_SEPARATOR) ||
                Str::endsWith(dirname($path), DIRECTORY_SEPARATOR.$excluded)) {
                return false;
            }
        }

        return true;
    }

    private function isExcludedDirectory(string $path): bool
    {
        return in_array(basename($path), self::ExcludedDirectories, true);
    }

    private function isWithin(string $root, string $path): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return Str::startsWith($path, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{
     *     files: list<array{
     *         path: string,
     *         reason: string,
     *         git_sha: ?string,
     *         excerpt: string,
     *         excerpt_line_count: int,
     *         excerpt_truncated: bool,
     *         symbols: list<string>,
     *     }>,
     *     repository_revision: null,
     *     repository_state: array{state: string, clean: false, head_sha: null, base_sha: null},
     *     selection_reason: string,
     * }
     */
    private function emptyResult(string $reason): array
    {
        return [
            'files' => [],
            'repository_revision' => null,
            'repository_state' => [
                'state' => 'unavailable',
                'clean' => false,
                'head_sha' => null,
                'base_sha' => null,
            ],
            'selection_reason' => $reason,
        ];
    }
}
