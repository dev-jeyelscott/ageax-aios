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
     *     files: list<array{path: string, reason: string, git_sha: ?string}>,
     *     repository_revision: ?string,
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

        $selected = [];

        if (isset($discoveryInputs['explicit_paths']) && is_array($discoveryInputs['explicit_paths'])) {
            foreach ($discoveryInputs['explicit_paths'] as $path) {
                $this->selectExplicitPath($projectPath, $path, $selected);
            }
        }

        if (isset($discoveryInputs['changed_files']) && is_array($discoveryInputs['changed_files'])) {
            foreach ($discoveryInputs['changed_files'] as $path) {
                $this->selectChangedFile($projectPath, $path, $selected);
            }
        }

        if (isset($discoveryInputs['task_terms']) && is_array($discoveryInputs['task_terms'])) {
            $searchDirs = isset($discoveryInputs['default_search_dirs']) && is_array($discoveryInputs['default_search_dirs'])
                ? $discoveryInputs['default_search_dirs']
                : ['.'];

            $this->selectByTermsInDirs($projectPath, $discoveryInputs['task_terms'], $searchDirs, $selected);
        }

        if (empty($selected) && isset($discoveryInputs['default_search_dirs']) && is_array($discoveryInputs['default_search_dirs'])) {
            $this->selectFromDirectories($projectPath, $discoveryInputs['default_search_dirs'], $selected);
        }

        $files = [];
        $count = 0;

        foreach ($selected as $path => $reason) {
            if ($count >= self::MaxFiles) {
                break;
            }

            $resolved = $this->resolvePath($projectPath, $path);

            if ($resolved === null || ! is_file($resolved)) {
                continue;
            }

            if (! $this->isWithin($projectPath, $resolved)) {
                continue;
            }

            if (! $this->isSafeFile($resolved)) {
                continue;
            }

            try {
                $content = $this->files->get($resolved);

                if ($this->containsSecretMaterial($content)) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $files[] = [
                'path' => str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($projectPath) + 1)),
                'reason' => $reason,
                'git_sha' => $gitState['clean'] ? $gitState['head_sha'] : null,
            ];

            $count++;
        }

        return [
            'files' => $files,
            'repository_revision' => $gitState['clean'] ? $gitState['head_sha'] : null,
            'selection_reason' => 'deterministic_targeted_discovery_from_task_terms_and_changed_files',
        ];
    }

    private function selectExplicitPath(string $projectPath, string $path, array &$selected): void
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === null) {
            return;
        }

        if (! isset($selected[$normalized])) {
            $selected[$normalized] = 'explicit_path_from_discovery_input';
        }
    }

    private function selectChangedFile(string $projectPath, string $path, array &$selected): void
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === null) {
            return;
        }

        if (! isset($selected[$normalized])) {
            $selected[$normalized] = 'changed_file_from_git_metadata';
        }
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>  $directories
     */
    private function selectByTermsInDirs(string $projectPath, array $terms, array $directories, array &$selected): void
    {
        foreach ($directories as $directory) {
            if (! is_string($directory) || trim($directory) === '') {
                continue;
            }

            $searchPath = $projectPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

            if (! is_dir($searchPath) || ! $this->isWithin($projectPath, $searchPath)) {
                continue;
            }

            foreach ($terms as $term) {
                if (! is_string($term) || trim($term) === '') {
                    continue;
                }

                $this->searchDirectory($projectPath, $searchPath, $term, $selected);
            }
        }
    }

    /** @param  list<string>  $directories */
    private function selectFromDirectories(string $projectPath, array $directories, array &$selected): void
    {
        foreach ($directories as $directory) {
            if (! is_string($directory) || trim($directory) === '') {
                continue;
            }

            $path = $projectPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

            if (is_dir($path) && $this->isWithin($projectPath, $path)) {
                $this->addMarkdownFiles($path, $projectPath, $selected);
            }
        }
    }

    private function searchDirectory(string $projectPath, string $directory, string $term, array &$selected, int $depth = 0): void
    {
        if ($depth > 3 || count($selected) >= self::MaxFiles) {
            return;
        }

        try {
            foreach ($this->files->directories($directory) as $subdir) {
                if ($this->isExcludedDirectory($subdir)) {
                    continue;
                }

                $this->searchDirectory($projectPath, $subdir, $term, $selected, $depth + 1);
            }

            foreach ($this->files->files($directory) as $file) {
                if (count($selected) >= self::MaxFiles) {
                    break;
                }

                if (Str::lower($file->getExtension()) !== 'md') {
                    continue;
                }

                $filename = Str::lower($file->getFilename());

                if (Str::contains($filename, Str::lower($term))) {
                    $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                    $selected[$relativePath] ??= 'matched_task_term_in_filename';
                }
            }
        } catch (Throwable) {
            // Unreadable directory, skip
        }
    }

    private function addMarkdownFiles(string $directory, string $projectPath, array &$selected): void
    {
        try {
            foreach ($this->files->allFiles($directory) as $file) {
                if (count($selected) >= self::MaxFiles) {
                    break;
                }

                if (Str::lower($file->getExtension()) !== 'md') {
                    continue;
                }

                $realPath = $file->getRealPath();

                if (! is_string($realPath) || ! $this->isWithin($projectPath, $realPath)) {
                    continue;
                }

                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($projectPath) + 1));
                $selected[$relativePath] ??= 'found_in_default_directory';
            }
        } catch (Throwable) {
            // Unreadable directory, skip
        }
    }

    private function resolvePath(string $projectPath, string $path): ?string
    {
        $candidate = $projectPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $resolved = realpath($candidate);

        return is_string($resolved) ? $resolved : null;
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
        $basename = basename($path);

        return in_array($basename, self::ExcludedDirectories, true);
    }

    private function isWithin(string $root, string $path): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return Str::startsWith($path, $root.DIRECTORY_SEPARATOR);
    }

    /** @return array{files: list, repository_revision: null, selection_reason: string} */
    private function emptyResult(string $reason): array
    {
        return [
            'files' => [],
            'repository_revision' => null,
            'selection_reason' => $reason,
        ];
    }
}
