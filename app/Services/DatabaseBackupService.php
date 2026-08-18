<?php

namespace App\Services;

use App\Exceptions\DatabaseBackupFailed;
use App\Models\DatabaseBackup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Throwable;

/**
 * Independent disaster-recovery backup subsystem (P0 database protection hardening). Snapshots are
 * written outside both the AIOS repository and any managed project workspace (aios.backup_path,
 * default ~/.local/share/ageax-aios/backups), and every attempt is recorded in a ledger stored on
 * the separate "aios_backup_ledger" connection so it survives deletion of the primary AIOS
 * database. Unsupported drivers and in-memory SQLite connections fail closed rather than silently
 * skipping protection.
 */
class DatabaseBackupService
{
    /**
     * Which connection is "the primary AIOS database" defaults to database.default but can be
     * pinned independently via aios.database_connection. Kept as its own lookup (rather than always
     * reading database.default directly) so this can point at a distinct connection name without
     * touching the connection Laravel's own test harness (RefreshDatabase) manages.
     *
     * @return array{driver: string, connection_name: string}
     */
    public function connectionInfo(): array
    {
        $connectionName = (string) (config('aios.database_connection') ?: config('database.default'));

        return [
            'driver' => (string) config("database.connections.{$connectionName}.driver"),
            'connection_name' => $connectionName,
        ];
    }

    public function create(string $reason, ?int $agentRunId = null): DatabaseBackup
    {
        $this->ensureLedgerSchema();
        ['driver' => $driver, 'connection_name' => $connectionName] = $this->connectionInfo();

        $backup = DatabaseBackup::create([
            'status' => 'creating',
            'reason' => $reason,
            'driver' => $driver,
            'connection_name' => $connectionName,
            'agent_run_id' => $agentRunId,
            'started_at' => now(),
        ]);

        try {
            $artifact = match ($driver) {
                'sqlite' => $this->createSqliteSnapshot($connectionName),
                'pgsql' => $this->createPgsqlSnapshot($connectionName),
                'mysql', 'mariadb' => $this->createMysqlSnapshot($connectionName),
                default => throw new DatabaseBackupFailed("Unsupported database driver [{$driver}]; refusing to continue unprotected."),
            };

            $backup->update([
                'status' => 'completed',
                'artifact_path' => $artifact['path'],
                'size_bytes' => $artifact['size'],
                'checksum_sha256' => $artifact['checksum'],
                'integrity_verified' => true,
                'completed_at' => now(),
                'verified_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $backup->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception instanceof DatabaseBackupFailed ? $exception : new DatabaseBackupFailed($exception->getMessage(), previous: $exception);
        }

        $this->pruneRetention();

        return $backup->fresh();
    }

    public function verify(DatabaseBackup $backup): bool
    {
        $this->ensureLedgerSchema();

        if (blank($backup->artifact_path) || ! File::exists($backup->artifact_path)) {
            $backup->update(['status' => 'corrupted', 'integrity_verified' => false, 'verified_at' => now(), 'error' => 'Backup artifact is missing on disk.']);

            return false;
        }

        $actualChecksum = hash_file('sha256', $backup->artifact_path);

        if ($actualChecksum === false || $actualChecksum !== $backup->checksum_sha256) {
            $backup->update(['status' => 'corrupted', 'integrity_verified' => false, 'verified_at' => now(), 'error' => 'Backup artifact checksum does not match the recorded checksum.']);

            return false;
        }

        $structurallyValid = match ($backup->driver) {
            'sqlite' => $this->sqliteIntegrityCheck($backup->artifact_path),
            'pgsql' => $this->pgRestoreListIsValid($backup->artifact_path),
            default => filesize($backup->artifact_path) > 0,
        };

        if (! $structurallyValid) {
            $backup->update(['status' => 'corrupted', 'integrity_verified' => false, 'verified_at' => now(), 'error' => 'Backup artifact failed a driver-specific integrity check.']);

            return false;
        }

        $backup->update(['integrity_verified' => true, 'verified_at' => now()]);

        return true;
    }

    public function latestVerified(?int $freshnessSeconds = null): ?DatabaseBackup
    {
        $this->ensureLedgerSchema();

        $query = DatabaseBackup::query()
            ->where('status', 'completed')
            ->where('integrity_verified', true)
            ->orderByDesc('completed_at');

        if ($freshnessSeconds !== null) {
            $query->where('completed_at', '>=', now()->subSeconds($freshnessSeconds));
        }

        return $query->first();
    }

    /** Never removes the single most recent good backup, and never prunes based on a later failure. */
    public function pruneRetention(): void
    {
        $this->ensureLedgerSchema();
        $keep = max(1, (int) config('aios.backup_retention_count'));

        DatabaseBackup::query()
            ->where('status', 'completed')
            ->where('integrity_verified', true)
            ->orderByDesc('completed_at')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get()
            ->each(function (DatabaseBackup $backup): void {
                if (filled($backup->artifact_path) && File::exists($backup->artifact_path)) {
                    File::delete($backup->artifact_path);
                }

                $backup->delete();
            });
    }

    /**
     * Bootstraps the ledger schema on demand so the backup subsystem works the first time it is
     * used, without depending on an operator having already run
     * `php artisan migrate --database=aios_backup_ledger --path=database/migrations/backup-ledger`
     * (the documented explicit step for ops tooling; see that migration file for the schema this
     * mirrors). SQLite requires the database file to exist before a connection can be opened.
     */
    public function ensureLedgerSchema(): void
    {
        $path = (string) config('database.connections.aios_backup_ledger.database');
        File::ensureDirectoryExists(dirname($path), 0700);

        if (! File::exists($path)) {
            File::put($path, '');
        }

        if (Schema::connection('aios_backup_ledger')->hasTable('database_backups')) {
            return;
        }

        Schema::connection('aios_backup_ledger')->create('database_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->string('reason');
            $table->string('driver');
            $table->string('connection_name');
            $table->string('artifact_path')->nullable();
            $table->unsignedBigInteger('agent_run_id')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256')->nullable();
            $table->boolean('integrity_verified')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->json('restore_evidence')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'completed_at']);
        });
    }

    /** @return array{path: string, size: int, checksum: string} */
    private function createSqliteSnapshot(string $connectionName): array
    {
        $database = (string) config("database.connections.{$connectionName}.database");

        if ($database === ':memory:' || $database === '') {
            throw new DatabaseBackupFailed('The primary database is an in-memory SQLite connection; there is no durable file to snapshot.');
        }

        if (! File::exists($database)) {
            throw new DatabaseBackupFailed("The primary SQLite database file [{$database}] does not exist.");
        }

        $this->ensureBackupDirectory();
        $tmpPath = $this->backupDirectory().'/.tmp-'.Str::uuid().'.sqlite';

        try {
            // A dedicated PDO connection, independent of Laravel's own "sqlite" connection object
            // (which may be mid-transaction, e.g. under RefreshDatabase in tests): VACUUM cannot
            // run inside an open transaction, and VACUUM INTO produces a transactionally consistent
            // copy of a live SQLite database, unlike a raw filesystem copy, which can capture a
            // torn/inconsistent write.
            $pdo = new PDO('sqlite:'.$database);
            $pdo->exec('VACUUM INTO '.$pdo->quote($tmpPath));
        } catch (PDOException $exception) {
            throw new DatabaseBackupFailed('SQLite VACUUM INTO failed: '.$exception->getMessage(), previous: $exception);
        }

        if (! $this->sqliteIntegrityCheck($tmpPath)) {
            File::delete($tmpPath);

            throw new DatabaseBackupFailed('The SQLite snapshot failed its integrity check immediately after creation.');
        }

        $finalPath = $this->backupDirectory().'/sqlite-'.now()->format('Ymd-His').'-'.Str::uuid().'.sqlite';
        File::move($tmpPath, $finalPath);

        return $this->finalizeArtifact($finalPath);
    }

    private function sqliteIntegrityCheck(string $path): bool
    {
        try {
            $pdo = new PDO('sqlite:'.$path);
            $statement = $pdo->query('PRAGMA integrity_check;');

            return $statement !== false && $statement->fetchColumn() === 'ok';
        } catch (PDOException) {
            return false;
        }
    }

    /** @return array{path: string, size: int, checksum: string} */
    private function createPgsqlSnapshot(string $connectionName): array
    {
        $connection = (array) config("database.connections.{$connectionName}");
        $this->ensureBackupDirectory();
        $path = $this->backupDirectory().'/pgsql-'.now()->format('Ymd-His').'-'.Str::uuid().'.dump';

        $result = Process::env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
            ->timeout((int) config('aios.execution_timeout'))
            ->run([
                'pg_dump',
                '--host', (string) ($connection['host'] ?? '127.0.0.1'),
                '--port', (string) ($connection['port'] ?? '5432'),
                '--username', (string) ($connection['username'] ?? ''),
                '--format=custom',
                '--file', $path,
                (string) ($connection['database'] ?? ''),
            ]);

        if (! $result->successful()) {
            throw new DatabaseBackupFailed('pg_dump failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        if (! $this->pgRestoreListIsValid($path)) {
            throw new DatabaseBackupFailed('The PostgreSQL dump failed structural verification via pg_restore --list.');
        }

        return $this->finalizeArtifact($path);
    }

    private function pgRestoreListIsValid(string $path): bool
    {
        if (! File::exists($path) || filesize($path) === 0) {
            return false;
        }

        $result = Process::timeout((int) config('aios.execution_timeout'))->run(['pg_restore', '--list', $path]);

        return $result->successful();
    }

    /** @return array{path: string, size: int, checksum: string} */
    private function createMysqlSnapshot(string $connectionName): array
    {
        $connection = (array) config("database.connections.{$connectionName}");
        $this->ensureBackupDirectory();
        $path = $this->backupDirectory().'/mysql-'.now()->format('Ymd-His').'-'.Str::uuid().'.sql';

        $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->timeout((int) config('aios.execution_timeout'))
            ->run([
                'mysqldump',
                '--host', (string) ($connection['host'] ?? '127.0.0.1'),
                '--port', (string) ($connection['port'] ?? '3306'),
                '--user', (string) ($connection['username'] ?? ''),
                '--single-transaction',
                '--result-file', $path,
                (string) ($connection['database'] ?? ''),
            ]);

        if (! $result->successful() || ! File::exists($path) || filesize($path) === 0) {
            throw new DatabaseBackupFailed('mysqldump failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $this->finalizeArtifact($path);
    }

    /** @return array{path: string, size: int, checksum: string} */
    private function finalizeArtifact(string $path): array
    {
        $checksum = hash_file('sha256', $path);

        if ($checksum === false) {
            throw new DatabaseBackupFailed('Could not compute a checksum for the backup artifact.');
        }

        return ['path' => $path, 'size' => (int) filesize($path), 'checksum' => $checksum];
    }

    private function backupDirectory(): string
    {
        return rtrim((string) config('aios.backup_path'), '/');
    }

    private function ensureBackupDirectory(): void
    {
        File::ensureDirectoryExists($this->backupDirectory(), 0700);
    }
}
