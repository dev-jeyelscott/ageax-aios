<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\DatabaseBackup;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function cliFileBackedSqliteDatabase(): string
{
    $dbPath = sys_get_temp_dir().'/aios-cli-backup-source-'.uniqid().'.sqlite';
    touch($dbPath);
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE example (id INTEGER PRIMARY KEY, value TEXT)');
    $pdo->exec("INSERT INTO example (value) VALUES ('hello')");
    unset($pdo);

    config()->set('database.connections.aios_test_primary', ['driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '']);
    config()->set('aios.database_connection', 'aios_test_primary');

    return $dbPath;
}

function cliIsolatedLedger(): string
{
    $ledgerDir = sys_get_temp_dir().'/aios-cli-ledger-'.uniqid();
    config()->set('aios.backup_path', $ledgerDir);
    config()->set('database.connections.aios_backup_ledger.database', $ledgerDir.'/ledger.sqlite');
    DB::purge('aios_backup_ledger');

    return $ledgerDir;
}

test('aios:database-backup:create writes a verified backup to the ledger', function () {
    cliFileBackedSqliteDatabase();
    cliIsolatedLedger();

    Artisan::call('aios:database-backup:create', ['--reason' => 'test-run']);

    expect(Artisan::output())->toContain('completed')
        ->and(DatabaseBackup::query()->where('reason', 'test-run')->where('status', 'completed')->exists())->toBeTrue();
});

test('aios:database-backup:verify detects checksum corruption', function () {
    cliFileBackedSqliteDatabase();
    cliIsolatedLedger();
    $backup = app(DatabaseBackupService::class)->create('test-run');
    file_put_contents($backup->artifact_path, 'corrupted-bytes');

    $exitCode = Artisan::call('aios:database-backup:verify', ['id' => $backup->id]);

    expect($exitCode)->toBe(1)
        ->and($backup->fresh()->status)->toBe('corrupted');
});

test('aios:database-restore refuses when AgentRuns currently appear to be running, without --force', function () {
    cliFileBackedSqliteDatabase();
    cliIsolatedLedger();
    $backup = app(DatabaseBackupService::class)->create('test-run');
    $project = Project::create(['name' => 'Example', 'path' => sys_get_temp_dir().'/aios-cli-project-'.uniqid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    AgentRun::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'x'), 'started_at' => now()]);

    $exitCode = Artisan::call('aios:database-restore', ['id' => $backup->id]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('appear to be running')
        ->and($backup->fresh()->restored_at)->toBeNull();
});

test('aios:database-restore refuses a corrupted backup and never touches the primary database', function () {
    cliFileBackedSqliteDatabase();
    cliIsolatedLedger();
    $backup = app(DatabaseBackupService::class)->create('test-run');
    file_put_contents($backup->artifact_path, 'corrupted-bytes');

    $exitCode = Artisan::call('aios:database-restore', ['id' => $backup->id]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('not restorable');
});

test('aios:database-restore refuses while a restore lock is already present', function () {
    cliFileBackedSqliteDatabase();
    $ledgerDir = cliIsolatedLedger();
    $backup = app(DatabaseBackupService::class)->create('test-run');
    file_put_contents($ledgerDir.'/'.config('aios.database_restore_lock_filename'), 'locked');

    $this->artisan('aios:database-restore', ['id' => $backup->id, '--force' => true])
        ->expectsConfirmation('Restore the AIOS database from backup #'.$backup->id.' ('.$backup->completed_at.')? This overwrites the live database.', 'yes')
        ->assertExitCode(1);
});

test('aios:database-restore performs a driver-correct sqlite restore and reconnects', function () {
    $dbPath = cliFileBackedSqliteDatabase();
    cliIsolatedLedger();
    $backup = app(DatabaseBackupService::class)->create('test-run');

    // Simulate the exact incident: the primary database file is deleted after the backup.
    unlink($dbPath);
    file_put_contents($dbPath, '');

    $this->artisan('aios:database-restore', ['id' => $backup->id, '--force' => true])
        ->expectsConfirmation('Restore the AIOS database from backup #'.$backup->id.' ('.$backup->completed_at.')? This overwrites the live database.', 'yes')
        ->assertExitCode(0);

    expect($backup->fresh()->restored_at)->not->toBeNull()
        ->and($backup->fresh()->restore_evidence['reconnect_verified'] ?? null)->toBeTrue();

    $restored = new PDO('sqlite:'.$dbPath);
    expect($restored->query('SELECT value FROM example')->fetchColumn())->toBe('hello');
});
