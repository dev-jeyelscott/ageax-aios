<?php

namespace App\Services;

use App\Exceptions\DatabaseProtectionFailed;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * AIOS-owned, harness-independent pre-execution boundary (P0 database protection hardening). Runs
 * after the AgentRun is durably created but immediately before either Codex or Claude Code is
 * launched, wired into each protected role's existing operational-failure handling: Project
 * Manager, Coder, Reviewer, and the Workflow Recovery Engineer all require a verified recovery
 * point (and no active restore lock) before an external model process starts. Prompts, Agent
 * instructions, and Skills play no part in this decision.
 */
class DatabaseProtectionGuard
{
    public function __construct(
        private DatabaseBackupService $backups,
        private WorkspacePathResolver $paths,
    ) {}

    /**
     * @throws DatabaseProtectionFailed
     * @throws UnsafeProjectPath
     */
    public function guard(?Project $project = null): void
    {
        if ($this->restoreLockActive()) {
            throw new DatabaseProtectionFailed('A database restore is currently in progress; execution is blocked until it completes.');
        }

        if ($project !== null) {
            // Redundant with the harness runners' own assertProjectPath() call, and intentionally
            // so: this guard is a second, independent enforcement point that must fail closed on
            // its own even if a future caller forgets to path-check before invoking it.
            $this->paths->assertProjectPath($project->path);
        }

        $freshnessSeconds = max(0, (int) config('aios.database_protection_freshness_seconds'));
        $backup = $this->backups->latestVerified($freshnessSeconds > 0 ? $freshnessSeconds : null);

        if ($backup !== null) {
            return;
        }

        try {
            $this->backups->create('database_protection_guard');
        } catch (Throwable $exception) {
            throw new DatabaseProtectionFailed(
                'No verified recovery point exists and a fresh backup could not be created: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function restoreLockActive(): bool
    {
        return File::exists($this->restoreLockPath());
    }

    private function restoreLockPath(): string
    {
        return rtrim((string) config('aios.backup_path'), '/').'/'.config('aios.database_restore_lock_filename');
    }
}
