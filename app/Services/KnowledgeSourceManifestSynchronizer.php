<?php

namespace App\Services;

use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class KnowledgeSourceManifestSynchronizer
{
    private const string ObsidianSourceType = 'obsidian';

    private const string RepositorySourceType = 'repository';

    public const string ExternalObsidianSourceType = 'obsidian_external';

    private const int MaxSourceReferenceCharacters = 500;

    private const int ReconcileAttempts = 2;

    public function __construct(
        private Filesystem $files,
        private WorkspacePathResolver $paths,
        private ProjectGitState $git,
    ) {}

    /**
     * Synchronize every safe Markdown source inside only this project's configured Obsidian directory.
     *
     * This indexes metadata only. Missing files are deliberately not treated as superseded.
     */
    public function sync(Project $project): int
    {
        $directory = $this->obsidianProjectDirectory($project);

        if ($directory === null) {
            return 0;
        }

        try {
            $files = $this->files->allFiles($directory);
        } catch (Throwable) {
            return 0;
        }

        $observed = 0;

        foreach ($files as $file) {
            if (Str::lower($file->getExtension()) !== 'md') {
                continue;
            }

            $realPath = $file->getRealPath();

            if (! is_string($realPath) || ! $this->isWithin($directory, $realPath)) {
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

            $this->observeContent(
                $project,
                self::ObsidianSourceType,
                $reference,
                $content,
                null,
            );

            $observed++;
        }

        return $observed;
    }

    /**
     * Observe one explicitly requested repository file without recursively indexing the repository.
     *
     * Git SHA is recorded only when the repository is inspectable and clean, so working-tree
     * content is never falsely attributed to the current HEAD commit.
     */
    public function trackRepositoryFile(
        Project $project,
        string $relativePath,
    ): ?KnowledgeSourceManifest {
        $reference = $this->normalizeReference($relativePath);

        if ($reference === null) {
            return null;
        }

        $projectPath = $this->paths->assertProjectPath($project->path);
        $root = realpath($projectPath);

        if ($root === false) {
            return null;
        }

        $candidate = $root.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $reference,
        );

        $realPath = realpath($candidate);

        if (
            $realPath === false
            || ! is_file($realPath)
            || ! is_readable($realPath)
            || ! $this->isWithin($root, $realPath)
        ) {
            return null;
        }

        try {
            $content = $this->files->get($realPath);
        } catch (Throwable) {
            return null;
        }

        $state = $this->git->inspect($projectPath);
        $gitSha = $state['inspectable']
            && $state['clean']
            && is_string($state['head_sha'])
            ? $state['head_sha']
            : null;

        return $this->observeContent(
            $project,
            self::RepositorySourceType,
            $reference,
            $content,
            $gitSha,
        );
    }

    /**
     * Observe one already-read external Obsidian knowledge source version for a resolved project.
     *
     * External knowledge lives outside the project-local Obsidian directory, so its containment and
     * approval checks belong to the caller; this only reconciles temporal provenance so superseded
     * external versions stop being current.
     */
    public function observeExternalObsidianSource(
        Project $project,
        string $sourceReference,
        string $content,
    ): ?KnowledgeSourceManifest {
        $reference = $this->normalizeReference($sourceReference);

        if ($reference === null) {
            return null;
        }

        return $this->observeContent(
            $project,
            self::ExternalObsidianSourceType,
            $reference,
            $content,
            null,
        );
    }

    /**
     * Hash exact source bytes and reconcile the observed version without persisting the content.
     */
    private function observeContent(
        Project $project,
        string $sourceType,
        string $sourceReference,
        string $content,
        ?string $gitSha,
    ): KnowledgeSourceManifest {
        return $this->observeHash(
            $project,
            $sourceType,
            $sourceReference,
            hash('sha256', $content),
            $gitSha,
        );
    }

    /**
     * Reconcile one observed source version atomically and preserve all historical versions.
     */
    private function observeHash(
        Project $project,
        string $sourceType,
        string $sourceReference,
        string $contentHash,
        ?string $gitSha,
    ): KnowledgeSourceManifest {
        $observedAt = now();

        for ($attempt = 1; $attempt <= self::ReconcileAttempts; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $project,
                    $sourceType,
                    $sourceReference,
                    $contentHash,
                    $gitSha,
                    $observedAt,
                ): KnowledgeSourceManifest {
                    $current = KnowledgeSourceManifest::query()
                        ->whereBelongsTo($project)
                        ->where('source_type', $sourceType)
                        ->where('source_reference', $sourceReference)
                        ->whereNull('superseded_at')
                        ->lockForUpdate()
                        ->first();

                    if (
                        $current instanceof KnowledgeSourceManifest
                        && hash_equals($current->content_hash, $contentHash)
                    ) {
                        $current->forceFill([
                            'last_verified_at' => $observedAt,
                        ])->save();

                        return $current->refresh();
                    }

                    if ($current instanceof KnowledgeSourceManifest) {
                        $current->forceFill([
                            'superseded_at' => $observedAt,
                        ])->save();
                    }

                    $replacement = KnowledgeSourceManifest::query()->create([
                        'project_id' => $project->id,
                        'source_type' => $sourceType,
                        'source_reference' => $sourceReference,
                        'content_hash' => $contentHash,
                        'git_sha' => $gitSha,
                        'discovered_at' => $observedAt,
                        'last_verified_at' => $observedAt,
                    ]);

                    if ($current instanceof KnowledgeSourceManifest) {
                        $current->forceFill([
                            'superseded_by_id' => $replacement->id,
                        ])->save();
                    }

                    return $replacement;
                }, attempts: 3);
            } catch (QueryException $exception) {
                if (
                    $attempt < self::ReconcileAttempts
                    && $this->isUniqueConstraintViolation($exception)
                ) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new LogicException(
            'Knowledge source manifest reconciliation exhausted unexpectedly.',
        );
    }

    /**
     * Resolve the existing project-local Obsidian directory without creating or traversing the vault.
     */
    private function obsidianProjectDirectory(Project $project): ?string
    {
        $vault = config('aios.obsidian_vault_path');

        if (! is_string($vault) || $vault === '') {
            return null;
        }

        $directory = $vault.'/Projects/'.Str::slug($project->name);
        $realDirectory = realpath($directory);

        if (
            $realDirectory === false
            || ! is_dir($realDirectory)
            || ! is_readable($realDirectory)
        ) {
            return null;
        }

        return rtrim($realDirectory, DIRECTORY_SEPARATOR);
    }

    /**
     * Normalize a stable project-relative reference and reject traversal or absolute paths.
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
     * Detect PostgreSQL or SQLite unique-key races so reconciliation can retry once.
     */
    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = is_array($exception->errorInfo)
            && isset($exception->errorInfo[0])
                ? (string) $exception->errorInfo[0]
                : (string) $exception->getCode();

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
