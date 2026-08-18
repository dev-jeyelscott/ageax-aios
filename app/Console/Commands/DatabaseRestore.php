<?php

namespace App\Console\Commands;

use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\DatabaseBackup;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * CLI-first disaster recovery, independent of the primary AIOS database, users, sessions,
 * queue/cache state, web UI, and either LLM harness: it reads the isolated ledger directly, so it
 * works even when the primary database that would normally back an authenticated web session is
 * the very thing being restored.
 */
#[Signature('aios:database-restore {id : Backup ledger ID to restore} {--force : Restore even if AgentRuns currently appear to be running}')]
#[Description('Restore the AIOS database from a verified independent backup, with a filesystem restore lock blocking concurrent work')]
class DatabaseRestore extends Command
{
    public function handle(DatabaseBackupService $backups): int
    {
        $backup = DatabaseBackup::query()->find((int) $this->argument('id'));

        if ($backup === null) {
            $this->error("No backup with ID [{$this->argument('id')}] exists in the ledger.");

            return self::FAILURE;
        }

        if (! $backup->isRestorable() || ! $backups->verify($backup->fresh())) {
            $this->error("Backup #{$backup->id} is not restorable: it is missing required completion state, or failed checksum/integrity verification.");

            return self::FAILURE;
        }

        $connectionInfo = $backups->connectionInfo();

        if ($backup->driver !== $connectionInfo['driver']) {
            $this->error("Backup #{$backup->id} was taken from a [{$backup->driver}] database, but the currently configured connection is [{$connectionInfo['driver']}]; refusing an incompatible restore.");

            return self::FAILURE;
        }

        if (! $this->option('force') && AgentRun::query()->where('status', AgentRunStatus::Running)->exists()) {
            $this->error('AgentRuns currently appear to be running. Stop AIOS workers first, or pass --force to override this quiescence check.');

            return self::FAILURE;
        }

        if (! $this->confirm("Restore the AIOS database from backup #{$backup->id} ({$backup->completed_at})? This overwrites the live database.", false)) {
            $this->warn('Restore cancelled.');

            return self::SUCCESS;
        }

        $lockPath = $this->lockPath();

        if (File::exists($lockPath)) {
            $this->error('A restore is already in progress (restore lock file present). If this is stale, remove it manually before retrying.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($lockPath), 0700);
        File::put($lockPath, json_encode(['backup_id' => $backup->id, 'started_at' => now()->toISOString()], JSON_THROW_ON_ERROR));

        try {
            $evidence = match ($backup->driver) {
                'sqlite' => $this->restoreSqlite($backup, $connectionInfo['connection_name']),
                'pgsql' => $this->restorePgsql($backup, $connectionInfo['connection_name']),
                'mysql', 'mariadb' => $this->restoreMysql($backup, $connectionInfo['connection_name']),
                default => throw new \RuntimeException("Unsupported restore driver [{$backup->driver}]."),
            };
        } catch (\Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage());
            $backup->update(['restore_evidence' => ['error' => $exception->getMessage(), 'attempted_at' => now()->toISOString()]]);

            return self::FAILURE;
        } finally {
            File::delete($lockPath);
        }

        $backup->update(['restored_at' => now(), 'restore_evidence' => $evidence]);
        $this->info("Backup #{$backup->id} restored and reconnected successfully.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function restoreSqlite(DatabaseBackup $backup, string $connectionName): array
    {
        $targetPath = (string) config("database.connections.{$connectionName}.database");

        if ($targetPath === ':memory:' || $targetPath === '') {
            throw new \RuntimeException('The primary connection is an in-memory SQLite database; there is no file to restore into.');
        }

        $tmpPath = $targetPath.'.restoring-'.uniqid();
        File::copy((string) $backup->artifact_path, $tmpPath);
        File::move($tmpPath, $targetPath);

        DB::purge($connectionName);
        $verified = DB::connection($connectionName)->select('select 1 as ok');

        return ['driver' => 'sqlite', 'target' => $targetPath, 'reconnect_verified' => ($verified[0]->ok ?? null) == 1];
    }

    /** @return array<string, mixed> */
    private function restorePgsql(DatabaseBackup $backup, string $connectionName): array
    {
        $connection = (array) config("database.connections.{$connectionName}");

        $result = Process::env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
            ->timeout((int) config('aios.execution_timeout'))
            ->run([
                'pg_restore',
                '--host', (string) ($connection['host'] ?? '127.0.0.1'),
                '--port', (string) ($connection['port'] ?? '5432'),
                '--username', (string) ($connection['username'] ?? ''),
                '--dbname', (string) ($connection['database'] ?? ''),
                '--clean', '--if-exists', '--no-owner',
                (string) $backup->artifact_path,
            ]);

        if (! $result->successful()) {
            throw new \RuntimeException('pg_restore failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        DB::purge($connectionName);
        $verified = DB::connection($connectionName)->select('select 1 as ok');

        return ['driver' => 'pgsql', 'reconnect_verified' => ($verified[0]->ok ?? null) == 1, 'pg_restore_output' => trim($result->errorOutput())];
    }

    /** @return array<string, mixed> */
    private function restoreMysql(DatabaseBackup $backup, string $connectionName): array
    {
        $connection = (array) config("database.connections.{$connectionName}");
        $input = File::get((string) $backup->artifact_path);

        $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->timeout((int) config('aios.execution_timeout'))
            ->input($input)
            ->run([
                'mysql',
                '--host', (string) ($connection['host'] ?? '127.0.0.1'),
                '--port', (string) ($connection['port'] ?? '3306'),
                '--user', (string) ($connection['username'] ?? ''),
                (string) ($connection['database'] ?? ''),
            ]);

        if (! $result->successful()) {
            throw new \RuntimeException('mysql restore failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        DB::purge($connectionName);
        $verified = DB::connection($connectionName)->select('select 1 as ok');

        return ['driver' => 'mysql', 'reconnect_verified' => ($verified[0]->ok ?? null) == 1];
    }

    private function lockPath(): string
    {
        return rtrim((string) config('aios.backup_path'), '/').'/'.config('aios.database_restore_lock_filename');
    }
}
