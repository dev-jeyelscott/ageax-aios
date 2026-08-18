<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:database-backup:verify {id : Backup ledger ID}')]
#[Description('Independently re-verify an AIOS database backup artifact\'s checksum and integrity')]
class DatabaseBackupVerify extends Command
{
    public function handle(DatabaseBackupService $backups): int
    {
        $backup = DatabaseBackup::query()->find((int) $this->argument('id'));

        if ($backup === null) {
            $this->error("No backup with ID [{$this->argument('id')}] exists in the ledger.");

            return self::FAILURE;
        }

        $verified = $backups->verify($backup);

        if (! $verified) {
            $this->error("Backup #{$backup->id} failed verification: ".($backup->fresh()->error ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info("Backup #{$backup->id} verified: checksum and driver-specific integrity check both passed.");

        return self::SUCCESS;
    }
}
