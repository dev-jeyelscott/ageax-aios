<?php

namespace App\Services;

use App\Contracts\Context\ProjectResolution;
use App\Contracts\Context\ProjectResolutionMethod;
use App\Exceptions\ProjectResolutionFailed;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Project;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Resolves a standalone repository directory or an explicit AIOS Project reference to exactly one
 * registered Project, using durable repository identity so a main checkout and its Git worktrees
 * resolve identically. Reuses WorkspacePathResolver's existing traversal/symlink/AIOS-installation
 * protections and never trusts a caller-supplied folder name. Every ambiguous or unsafe input fails
 * closed; only scoped identity evidence (Project ID and matched precedence tier) is returned, never
 * raw repository content, remote URLs, or secrets.
 */
class ContextGatewayProjectResolver
{
    public function __construct(private WorkspacePathResolver $paths) {}

    public function resolve(?int $explicitProjectId, ?string $repositoryPath = null): ProjectResolution
    {
        if ($explicitProjectId !== null) {
            $project = Project::query()->find($explicitProjectId);

            if ($project === null) {
                throw new ProjectResolutionFailed("No registered AIOS Project matches the explicit Project ID [{$explicitProjectId}].");
            }

            return new ProjectResolution($project->id, ProjectResolutionMethod::ExplicitProjectId);
        }

        if ($repositoryPath === null || trim($repositoryPath) === '') {
            throw new ProjectResolutionFailed('Project resolution requires either an explicit Project ID or a repository directory.');
        }

        // Fails closed on traversal, symlink-escaping, out-of-workspace, and AIOS-installation paths.
        $resolvedPath = $this->paths->assertProjectPath($repositoryPath);

        if (! is_dir($resolvedPath)) {
            throw new ProjectResolutionFailed('The repository directory could not be found.');
        }

        $canonicalRemote = $this->canonicalRemote($resolvedPath);

        if ($canonicalRemote !== null) {
            $matched = $this->uniqueMatch(
                $this->matchProjects(fn (string $projectPath): ?string => $this->canonicalRemote($projectPath), $canonicalRemote),
                'canonical Git remote',
            );

            if ($matched !== null) {
                return new ProjectResolution($matched, ProjectResolutionMethod::CanonicalGitRemote);
            }
        }

        $repositoryIdentity = $this->repositoryIdentity($resolvedPath);

        if ($repositoryIdentity !== null) {
            $matched = $this->uniqueMatch(
                $this->matchProjects(fn (string $projectPath): ?string => $this->repositoryIdentity($projectPath), $repositoryIdentity),
                'registered repository identity',
            );

            if ($matched !== null) {
                return new ProjectResolution($matched, ProjectResolutionMethod::RegisteredRepositoryIdentity);
            }
        }

        $project = Project::query()->where('path', $resolvedPath)->first();

        if ($project !== null) {
            return new ProjectResolution($project->id, ProjectResolutionMethod::WorkspacePathFallback);
        }

        throw new ProjectResolutionFailed('This repository directory does not match any registered AIOS Project.');
    }

    /**
     * @param  callable(string): ?string  $identityOf
     * @return list<int>
     */
    private function matchProjects(callable $identityOf, string $expected): array
    {
        $matches = [];

        foreach (Project::query()->select(['id', 'path'])->get() as $project) {
            try {
                $projectPath = $this->paths->assertProjectPath($project->path);
            } catch (UnsafeProjectPath) {
                continue;
            }

            if (! is_dir($projectPath)) {
                continue;
            }

            if ($identityOf($projectPath) === $expected) {
                $matches[] = $project->id;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param  list<int>  $matches
     */
    private function uniqueMatch(array $matches, string $tierLabel): ?int
    {
        if (count($matches) > 1) {
            throw new ProjectResolutionFailed("Multiple registered AIOS Projects match the same {$tierLabel}; resolution must fail closed.");
        }

        return $matches[0] ?? null;
    }

    private function canonicalRemote(string $path): ?string
    {
        if (! $this->isGitWorkTree($path)) {
            return null;
        }

        $remoteNames = $this->run($path, ['git', 'remote']);

        if ($remoteNames === null || trim($remoteNames) === '') {
            return null;
        }

        $names = array_values(array_filter(array_map('trim', explode("\n", $remoteNames))));
        $primary = in_array('origin', $names, true) ? 'origin' : ($names[0] ?? null);

        if ($primary === null) {
            return null;
        }

        $url = $this->run($path, ['git', 'remote', 'get-url', $primary]);

        if ($url === null || trim($url) === '') {
            return null;
        }

        return $this->canonicalizeRemoteUrl(trim($url));
    }

    /**
     * Durable repository identity that a main checkout and every one of its Git worktrees share:
     * the absolute, symlink-resolved path of the shared Git common directory. Returned only as a
     * hash so no filesystem layout is exposed as evidence.
     */
    private function repositoryIdentity(string $path): ?string
    {
        if (! $this->isGitWorkTree($path)) {
            return null;
        }

        $commonDir = $this->run($path, ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir']);

        if ($commonDir === null || trim($commonDir) === '') {
            return null;
        }

        $resolved = realpath(trim($commonDir));

        if ($resolved === false) {
            return null;
        }

        return hash('sha256', $resolved);
    }

    private function canonicalizeRemoteUrl(string $url): ?string
    {
        if (preg_match('#^[\w.\-]+@([\w.\-]+):(.+)$#', $url, $matches) === 1 && ! str_contains($url, '://')) {
            $host = $matches[1];
            $repositoryPath = $matches[2];
        } else {
            $withoutScheme = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $url) ?? $url;
            $withoutCredentials = preg_replace('#^[^@/]+@#', '', $withoutScheme) ?? $withoutScheme;
            $slashPosition = strpos($withoutCredentials, '/');

            if ($slashPosition === false) {
                return null;
            }

            $host = preg_replace('/:\d+$/', '', substr($withoutCredentials, 0, $slashPosition)) ?? '';
            $repositoryPath = substr($withoutCredentials, $slashPosition + 1);
        }

        $repositoryPath = rtrim($repositoryPath, '/');
        $repositoryPath = preg_replace('#\.git$#', '', $repositoryPath) ?? $repositoryPath;

        if ($repositoryPath === '') {
            return null;
        }

        // Hashed immediately: an embedded credential that survived canonicalization must never be
        // exposed as resolution evidence, only used to determine equality between repositories.
        return hash('sha256', strtolower($host).'/'.$repositoryPath);
    }

    private function isGitWorkTree(string $path): bool
    {
        $output = $this->run($path, ['git', 'rev-parse', '--is-inside-work-tree']);

        return $output !== null && trim($output) === 'true';
    }

    /** @param  list<string>  $command */
    private function run(string $path, array $command): ?string
    {
        try {
            $result = Process::path($path)->run($command);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? $result->output() : null;
    }
}
