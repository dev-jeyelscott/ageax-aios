<?php

namespace App\Services;

use App\Exceptions\ProjectDatabaseCollision;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Detects when a managed project's own database configuration resolves to the same physical
 * database as AIOS's own primary connection. AIOS's WorkspacePathResolver and
 * SanitizedExecutionEnvironment together stop a managed project from reading or writing AIOS's
 * repository or inheriting AIOS's database credentials through AIOS-orchestrated execution, but a
 * project's own `.env` is loaded by that project's own process (whether run by AIOS, a developer's
 * terminal, or any other tool) — entirely outside AIOS's control. If that `.env` is misconfigured
 * to point at AIOS's own database name/host, any migration run against that project (through AIOS
 * or otherwise) can destroy AIOS's primary database without ever touching the AIOS repository or
 * inheriting AIOS's process environment. This guard catches that specific configuration mistake
 * proactively, at project registration and again before every execution.
 */
class ProjectDatabaseIsolationGuard
{
    /**
     * @throws ProjectDatabaseCollision
     */
    public function assertNoCollision(Project $project): void
    {
        $projectDatabase = $this->readProjectDatabaseIdentity($project->path);

        if ($projectDatabase === null) {
            return;
        }

        $aiosDatabase = $this->aiosDatabaseIdentity();

        if ($this->identitiesCollide($aiosDatabase, $projectDatabase)) {
            throw new ProjectDatabaseCollision(
                "Project [{$project->name}]'s own .env configures a database that resolves to AIOS's own primary database ".
                "(driver [{$aiosDatabase['driver']}], database [{$aiosDatabase['database']}]). Point this project at its own, ".
                'dedicated database before AIOS may execute against it.',
            );
        }
    }

    /** @return array{driver: string, host: string, database: string} */
    private function aiosDatabaseIdentity(): array
    {
        $connectionName = (string) (config('aios.database_connection') ?: config('database.default'));
        $connection = (array) config("database.connections.{$connectionName}");

        return $this->normalize(
            (string) ($connection['driver'] ?? ''),
            (string) ($connection['host'] ?? ''),
            (string) ($connection['database'] ?? ''),
        );
    }

    /** @return ?array{driver: string, host: string, database: string} */
    private function readProjectDatabaseIdentity(string $projectPath): ?array
    {
        $envPath = rtrim($projectPath, '/').'/.env';

        if (! File::exists($envPath)) {
            return null;
        }

        $values = $this->parseEnvFile(File::get($envPath));
        $driver = $values['DB_CONNECTION'] ?? null;

        if ($driver === null || ! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            return null;
        }

        return $this->normalize($driver, $values['DB_HOST'] ?? '', $values['DB_DATABASE'] ?? '');
    }

    /** @return array{driver: string, host: string, database: string} */
    private function normalize(string $driver, string $host, string $database): array
    {
        return [
            'driver' => $driver,
            'host' => $this->normalizeHost($host),
            'database' => $driver === 'sqlite' ? $this->normalizeSqlitePath($database) : $database,
        ];
    }

    private function normalizeHost(string $host): string
    {
        // Loopback TCP and a local Unix socket typically reach the same local server instance;
        // treat every local representation as equivalent so this check isn't defeated merely by
        // one side using 127.0.0.1 and the other a socket path.
        return in_array($host, ['127.0.0.1', 'localhost', '::1', ''], true) || Str::startsWith($host, '/')
            ? 'local'
            : $host;
    }

    private function normalizeSqlitePath(string $database): string
    {
        if ($database === ':memory:' || $database === '') {
            return $database;
        }

        return realpath($database) ?: $database;
    }

    /**
     * @param  array{driver: string, host: string, database: string}  $aios
     * @param  array{driver: string, host: string, database: string}  $project
     */
    private function identitiesCollide(array $aios, array $project): bool
    {
        if ($aios['database'] === '' || $project['database'] === '') {
            return false;
        }

        if ($aios['driver'] !== $project['driver']) {
            return false;
        }

        if ($aios['driver'] === 'sqlite') {
            return $aios['database'] === $project['database'];
        }

        return $aios['database'] === $project['database'] && $aios['host'] === $project['host'];
    }

    /** @return array<string, string> */
    private function parseEnvFile(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || Str::startsWith($line, '#') || ! Str::contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = preg_replace('/^"(.*)"$/', '$1', $value) ?? $value;
            $value = preg_replace("/^'(.*)'$/", '$1', $value) ?? $value;
            $values[$key] = $value;
        }

        return $values;
    }
}
