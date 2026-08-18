<?php

use App\Exceptions\DatabaseBackupFailed;
use App\Models\DatabaseBackup;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\DB;

/**
 * Every test here points aios.database_connection at a dedicated "aios_test_primary" connection
 * instead of mutating database.default/connections.sqlite.* directly: those are the connection
 * RefreshDatabase itself manages for the whole test suite, and reconfiguring them mid-test breaks
 * its own post-test transaction rollback. Using an independent connection name that Laravel's test
 * harness never touches keeps these tests isolated from that machinery entirely.
 */
function useFileBackedSqliteDatabase(): string
{
    $dbPath = sys_get_temp_dir().'/aios-backup-source-'.uniqid().'.sqlite';
    touch($dbPath);
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE example (id INTEGER PRIMARY KEY, value TEXT)');
    $pdo->exec("INSERT INTO example (value) VALUES ('hello')");
    unset($pdo);

    config()->set('database.connections.aios_test_primary', ['driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '']);
    config()->set('aios.database_connection', 'aios_test_primary');

    return $dbPath;
}

function useIsolatedLedger(): string
{
    $ledgerDir = sys_get_temp_dir().'/aios-ledger-'.uniqid();
    config()->set('aios.backup_path', $ledgerDir);
    config()->set('database.connections.aios_backup_ledger.database', $ledgerDir.'/ledger.sqlite');
    DB::purge('aios_backup_ledger');

    return $ledgerDir;
}

test('creates a verified sqlite snapshot using VACUUM INTO with a checksum', function () {
    useFileBackedSqliteDatabase();
    useIsolatedLedger();

    $backup = app(DatabaseBackupService::class)->create('scheduled');

    expect($backup->status)->toBe('completed')
        ->and($backup->driver)->toBe('sqlite')
        ->and($backup->connection_name)->toBe('aios_test_primary')
        ->and($backup->integrity_verified)->toBeTrue()
        ->and($backup->size_bytes)->toBeGreaterThan(0)
        ->and($backup->checksum_sha256)->not->toBeNull()
        ->and(file_exists($backup->artifact_path))->toBeTrue();

    $copy = new PDO('sqlite:'.$backup->artifact_path);
    expect($copy->query('SELECT value FROM example')->fetchColumn())->toBe('hello');
});

test('refuses to snapshot an in-memory sqlite database and fails closed', function () {
    config()->set('database.connections.aios_test_primary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('aios.database_connection', 'aios_test_primary');
    useIsolatedLedger();

    expect(fn () => app(DatabaseBackupService::class)->create('scheduled'))->toThrow(DatabaseBackupFailed::class);

    $backup = DatabaseBackup::query()->latest('id')->first();
    expect($backup->status)->toBe('failed')
        ->and($backup->error)->toContain('in-memory');
});

test('fails closed for an unsupported configured database driver', function () {
    useIsolatedLedger();
    config()->set('database.connections.aios_test_primary', ['driver' => 'sqlsrv', 'database' => 'unused']);
    config()->set('aios.database_connection', 'aios_test_primary');

    expect(fn () => app(DatabaseBackupService::class)->create('scheduled'))->toThrow(DatabaseBackupFailed::class);

    $backup = DatabaseBackup::query()->latest('id')->first();
    expect($backup->status)->toBe('failed');
});

test('verify() detects artifact checksum corruption and marks the backup corrupted', function () {
    useFileBackedSqliteDatabase();
    useIsolatedLedger();
    $backup = app(DatabaseBackupService::class)->create('scheduled');

    file_put_contents($backup->artifact_path, 'corrupted-bytes');

    expect(app(DatabaseBackupService::class)->verify($backup->fresh()))->toBeFalse()
        ->and($backup->fresh()->status)->toBe('corrupted')
        ->and($backup->fresh()->integrity_verified)->toBeFalse();
});

test('latestVerified() honors a freshness window', function () {
    useFileBackedSqliteDatabase();
    useIsolatedLedger();
    $backup = app(DatabaseBackupService::class)->create('scheduled');
    $backup->update(['completed_at' => now()->subHours(2)]);

    $service = app(DatabaseBackupService::class);

    expect($service->latestVerified(freshnessSeconds: 3600))->toBeNull()
        ->and($service->latestVerified(freshnessSeconds: 3600 * 3)?->id)->toBe($backup->id)
        ->and($service->latestVerified()->id)->toBe($backup->id);
});

test('retention pruning keeps the most recent successful backups and never removes the latest good one', function () {
    useFileBackedSqliteDatabase();
    useIsolatedLedger();
    config()->set('aios.backup_retention_count', 2);
    $service = app(DatabaseBackupService::class);

    $first = $service->create('scheduled');
    $second = $service->create('scheduled');
    $third = $service->create('scheduled');

    expect(DatabaseBackup::query()->count())->toBe(2)
        ->and(DatabaseBackup::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and(DatabaseBackup::query()->whereKey($second->id)->exists())->toBeTrue()
        ->and(DatabaseBackup::query()->whereKey($third->id)->exists())->toBeTrue();
});

test('the backup ledger survives even when the primary database connection is gone', function () {
    $dbPath = useFileBackedSqliteDatabase();
    useIsolatedLedger();
    $backup = app(DatabaseBackupService::class)->create('scheduled');

    // Simulate the exact incident: the primary AIOS database file is deleted outright.
    unlink($dbPath);

    expect(file_exists($backup->artifact_path))->toBeTrue()
        ->and(DatabaseBackup::query()->whereKey($backup->id)->first()?->status)->toBe('completed');
});
