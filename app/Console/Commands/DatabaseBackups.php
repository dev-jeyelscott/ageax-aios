<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aios:database-backups {--limit=20}')]
#[Description('List recent independent disaster-recovery database backups')]
class DatabaseBackups extends Command
{
    public function handle(): int
    {
        $backups = DatabaseBackup::query()
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($backups->isEmpty()) {
            $this->info('No database backups recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Status', 'Reason', 'Driver', 'Size', 'Checksum', 'Verified', 'Completed At', 'Agent Run'],
            $backups->map(fn (DatabaseBackup $backup): array => [
                $backup->id,
                $backup->status,
                $backup->reason,
                $backup->driver,
                $backup->size_bytes === null ? '-' : number_format($backup->size_bytes).' B',
                $backup->checksum_sha256 === null ? '-' : substr($backup->checksum_sha256, 0, 12).'…',
                $backup->integrity_verified ? 'yes' : 'no',
                $backup->completed_at?->toDateTimeString() ?? '-',
                $backup->agent_run_id ?? '-',
            ]),
        );

        return self::SUCCESS;
    }
}
