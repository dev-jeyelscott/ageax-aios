<?php

namespace App\Console\Commands;

use App\Exceptions\DatabaseBackupFailed;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:database-backup:create {--reason=manual : Why this backup was taken}')]
#[Description('Create a verified, driver-consistent AIOS database backup independent of the primary database')]
class DatabaseBackupCreate extends Command
{
    public function handle(DatabaseBackupService $backups): int
    {
        try {
            $backup = $backups->create((string) $this->option('reason'));
        } catch (DatabaseBackupFailed $exception) {
            $this->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup #{$backup->id} completed ({$backup->driver}, ".number_format((int) $backup->size_bytes)." bytes, checksum {$backup->checksum_sha256}).");
        $this->line("Artifact: {$backup->artifact_path}");

        return self::SUCCESS;
    }
}
