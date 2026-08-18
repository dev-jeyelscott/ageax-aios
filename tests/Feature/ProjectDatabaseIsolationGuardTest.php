<?php

use App\Exceptions\ProjectDatabaseCollision;
use App\Models\Project;
use App\Services\ProjectDatabaseIsolationGuard;

/**
 * Points aios.database_connection at a dedicated "aios_test_primary" connection instead of
 * mutating database.default/connections.sqlite.pgsql directly: those are managed by
 * RefreshDatabase for the whole suite, and reconfiguring them mid-test breaks its own post-test
 * transaction rollback (see DatabaseBackupServiceTest for the same pattern).
 */
function useAiosPrimaryConnection(array $connection): void
{
    config()->set('database.connections.aios_test_primary', $connection);
    config()->set('aios.database_connection', 'aios_test_primary');
}

function isolationGuardProject(string $envContents): Project
{
    $path = sys_get_temp_dir().'/aios-db-isolation-'.uniqid();
    mkdir($path, 0700, true);
    file_put_contents($path.'/.env', $envContents);

    return new Project(['name' => 'Example', 'path' => $path]);
}

test('detects a project pointing at the same pgsql database and host as AIOS', function () {
    useAiosPrimaryConnection(['driver' => 'pgsql', 'host' => '/var/run/postgresql', 'database' => 'ageax_aios']);
    $project = isolationGuardProject("DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_DATABASE=ageax_aios\n");

    expect(fn () => app(ProjectDatabaseIsolationGuard::class)->assertNoCollision($project))
        ->toThrow(ProjectDatabaseCollision::class);
});

test('treats a unix socket and loopback TCP host as the same local server', function () {
    useAiosPrimaryConnection(['driver' => 'pgsql', 'host' => '/var/run/postgresql', 'database' => 'shared_name']);
    $project = isolationGuardProject("DB_CONNECTION=pgsql\nDB_HOST=localhost\nDB_DATABASE=shared_name\n");

    expect(fn () => app(ProjectDatabaseIsolationGuard::class)->assertNoCollision($project))
        ->toThrow(ProjectDatabaseCollision::class);
});

test('allows a project with its own distinct database name', function () {
    useAiosPrimaryConnection(['driver' => 'pgsql', 'host' => '/var/run/postgresql', 'database' => 'ageax_aios']);
    $project = isolationGuardProject("DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_DATABASE=photobooth_thermal_vendo\n");

    app(ProjectDatabaseIsolationGuard::class)->assertNoCollision($project);
})->throwsNoExceptions();

test('allows a project on the same database name but a genuinely different remote host', function () {
    useAiosPrimaryConnection(['driver' => 'pgsql', 'host' => '/var/run/postgresql', 'database' => 'shared_name']);
    $project = isolationGuardProject("DB_CONNECTION=pgsql\nDB_HOST=db.example-remote.internal\nDB_DATABASE=shared_name\n");

    app(ProjectDatabaseIsolationGuard::class)->assertNoCollision($project);
})->throwsNoExceptions();

test('allows a project with no .env file at all', function () {
    useAiosPrimaryConnection(['driver' => 'pgsql', 'host' => '/var/run/postgresql', 'database' => 'ageax_aios']);
    $path = sys_get_temp_dir().'/aios-db-isolation-noenv-'.uniqid();
    mkdir($path, 0700, true);
    $project = new Project(['name' => 'Example', 'path' => $path]);

    app(ProjectDatabaseIsolationGuard::class)->assertNoCollision($project);
})->throwsNoExceptions();

test('detects a sqlite project pointing at the same database file as AIOS', function () {
    $sharedDb = sys_get_temp_dir().'/aios-shared-'.uniqid().'.sqlite';
    touch($sharedDb);
    useAiosPrimaryConnection(['driver' => 'sqlite', 'database' => $sharedDb]);
    $project = isolationGuardProject("DB_CONNECTION=sqlite\nDB_DATABASE={$sharedDb}\n");

    expect(fn () => app(ProjectDatabaseIsolationGuard::class)->assertNoCollision($project))
        ->toThrow(ProjectDatabaseCollision::class);
});
