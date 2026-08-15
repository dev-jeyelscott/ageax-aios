<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Project;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

class ProjectRuntimeCapabilities
{
    /** @var list<string> */
    private const array COMPOSE_FILES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    private const int COMMAND_TIMEOUT_SECONDS = 5;

    private const int MAX_SERVICES = 20;

    public function __construct(
        private WorkspacePathResolver $paths,
        private IsolatedProcessEnvironment $environment,
        private AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function forAgent(Project $project, AgentRole $role): array
    {
        $capabilities = $this->inspect($project->path);
        $this->audit->record('runtime.capabilities_detected', ['role' => $role->value, 'capabilities' => $capabilities], $project);

        return $capabilities;
    }

    /** @return array<string, mixed> */
    public function inspect(string $projectPath): array
    {
        $path = $this->paths->assertProjectPath($projectPath);
        $composeFile = $this->composeFile($path);

        $dockerCli = $this->executableAvailable('docker');
        $dockerCompose = $composeFile !== null && $dockerCli && $this->successful($path, ['docker', 'compose', 'version']);
        $dockerDaemon = $dockerCompose && $this->successful($path, ['docker', 'info', '--format', '{{.ServerVersion}}']);
        $hostPhp = $this->executableAvailable('php');

        $host = [
            'docker_cli_available' => $dockerCli,
            'docker_compose_available' => $dockerCompose,
            'docker_daemon_available' => $dockerDaemon,
            'psql_available' => $this->executableAvailable('psql'),
            'php_available' => $hostPhp,
            'pdo_pgsql_available' => $hostPhp && extension_loaded('pdo_pgsql'),
            'composer_available' => $this->executableAvailable('composer'),
            'node_available' => $this->executableAvailable('node'),
            'npm_available' => $this->executableAvailable('npm'),
        ];

        $services = [];
        $runningServices = [];
        $imagesContainPostgres = false;
        $containers = [];

        if ($composeFile !== null && $dockerCompose) {
            $services = $this->safeServiceNames($this->output($path, $this->composeCommand($composeFile, ['config', '--services', '--no-interpolate', '--no-env-resolution'])));
            $imagesContainPostgres = $this->outputContainsPostgres($path, $this->composeCommand($composeFile, ['config', '--images', '--no-interpolate', '--no-env-resolution']));

            if ($dockerDaemon) {
                $runningServices = $this->safeServiceNames($this->output($path, $this->composeCommand($composeFile, ['ps', '--services', '--status', 'running'])));
                $containers = $this->containerRows($this->output($path, $this->composeCommand($composeFile, ['ps', '--all', '--format', 'json'])));
            }
        }

        $composeDeclaresPostgres = $composeFile !== null && $this->composeDeclaresPostgres($path, $composeFile);
        $postgresService = $this->postgresService($services, $containers, $imagesContainPostgres);
        $postgresRunning = $postgresService !== null && in_array($postgresService, $runningServices, true);
        $psqlService = $postgresRunning && $this->successful($path, $this->composeCommand($composeFile, ['exec', '-T', $postgresService, 'psql', '--version']))
            ? $postgresService
            : null;
        $application = $composeFile !== null && $dockerCompose && $dockerDaemon
            ? $this->applicationCapabilities($path, $composeFile, $runningServices)
            : $this->emptyApplicationCapabilities();

        $project = [
            'compose' => [
                'configured' => $composeFile !== null,
                'file' => $composeFile,
                'services' => $services,
                'running_services' => $runningServices,
                'laravel_sail_available' => is_file($path.'/vendor/bin/sail'),
            ],
            'postgresql' => [
                'expected_in_container' => $composeFile !== null && ($composeDeclaresPostgres || $postgresService !== null || $imagesContainPostgres),
                'service' => $postgresService,
                'running' => $postgresRunning,
                'psql_service' => $psqlService,
            ],
            'application' => $application,
        ];

        return [
            'host' => $host,
            'project' => $project,
            'guidance' => $this->guidance($host, $project),
        ];
    }

    private function executableAvailable(string $executable): bool
    {
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$executable;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function composeDeclaresPostgres(string $path, string $composeFile): bool
    {
        $contents = file_get_contents($path.'/'.$composeFile, false, null, 0, 262144);
        if ($contents === false) {
            return false;
        }

        return preg_match('/^\s*image:\s*[\"\']?postgres(?:ql)?(?::|@|[\"\'\s]|$)/mi', $contents) === 1
            || preg_match('/^\s+[A-Za-z0-9_.-]*(?:postgres|pgsql)[A-Za-z0-9_.-]*:\s*(?:#.*)?$/mi', $contents) === 1;
    }

    private function composeFile(string $path): ?string
    {
        foreach (self::COMPOSE_FILES as $file) {
            if (is_file($path.'/'.$file)) {
                return $file;
            }
        }

        return null;
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function composeCommand(?string $composeFile, array $arguments): array
    {
        if ($composeFile === null) {
            return ['docker', 'compose', ...$arguments];
        }

        return ['docker', 'compose', '-f', $composeFile, ...$arguments];
    }

    /** @param list<string> $command */
    private function successful(string $path, array $command): bool
    {
        return $this->run($path, $command)?->successful() ?? false;
    }

    /** @param list<string> $command */
    private function output(string $path, array $command): string
    {
        $result = $this->run($path, $command);

        return $result?->successful() === true ? $result->output() : '';
    }

    /** @param list<string> $command */
    private function outputContainsPostgres(string $path, array $command): bool
    {
        return str_contains(strtolower($this->output($path, $command)), 'postgres');
    }

    /** @param list<string> $command */
    private function run(string $path, array $command): ?ProcessResult
    {
        try {
            return Process::path($path)
                ->timeout(self::COMMAND_TIMEOUT_SECONDS)
                ->run($this->environment->command($command));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function safeServiceNames(string $output): array
    {
        $services = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $service) {
            $service = trim($service);
            if ($service === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $service) !== 1) {
                continue;
            }

            $services[] = $service;
            if (count($services) >= self::MAX_SERVICES) {
                break;
            }
        }

        $services = array_values(array_unique($services));
        sort($services, SORT_STRING);

        return $services;
    }

    /** @return list<array{service: string, image: string, state: string}> */
    private function containerRows(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $rows = array_is_list($decoded) ? $decoded : [$decoded];
        $containers = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $service = is_string($row['Service'] ?? null) ? $row['Service'] : '';
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $service) !== 1) {
                continue;
            }

            $containers[] = [
                'service' => $service,
                'image' => is_string($row['Image'] ?? null) ? $row['Image'] : '',
                'state' => is_string($row['State'] ?? null) ? $row['State'] : '',
            ];

            if (count($containers) >= self::MAX_SERVICES) {
                break;
            }
        }

        return $containers;
    }

    /**
     * @param  list<string>  $services
     * @param  list<array{service: string, image: string, state: string}>  $containers
     */
    private function postgresService(array $services, array $containers, bool $imagesContainPostgres): ?string
    {
        foreach ($containers as $container) {
            if (str_contains(strtolower($container['image']), 'postgres')) {
                return $container['service'];
            }
        }

        foreach ($services as $service) {
            if (preg_match('/postgres|pgsql/i', $service) === 1) {
                return $service;
            }
        }

        if ($imagesContainPostgres) {
            $databaseServices = array_values(array_filter($services, fn (string $service): bool => preg_match('/^(?:db|database)$/i', $service) === 1));
            if (count($databaseServices) === 1) {
                return $databaseServices[0];
            }
        }

        return null;
    }

    /** @param list<string> $runningServices
     * @return array{service: ?string, php_available: bool, pdo_pgsql_available: bool, composer_available: bool, node_available: bool, npm_available: bool}
     */
    private function applicationCapabilities(string $path, string $composeFile, array $runningServices): array
    {
        $candidates = $runningServices;
        usort($candidates, function (string $left, string $right): int {
            $leftPreferred = preg_match('/(?:app|php|web|laravel|backend)/i', $left) === 1;
            $rightPreferred = preg_match('/(?:app|php|web|laravel|backend)/i', $right) === 1;

            return $leftPreferred === $rightPreferred ? strcmp($left, $right) : ($leftPreferred ? -1 : 1);
        });

        foreach (array_slice($candidates, 0, 6) as $service) {
            if (! $this->successful($path, $this->composeCommand($composeFile, ['exec', '-T', $service, 'php', '-v']))) {
                continue;
            }

            return [
                'service' => $service,
                'php_available' => true,
                'pdo_pgsql_available' => $this->successful($path, $this->composeCommand($composeFile, ['exec', '-T', $service, 'php', '-r', 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'])),
                'composer_available' => $this->successful($path, $this->composeCommand($composeFile, ['exec', '-T', $service, 'composer', '--version', '--no-ansi'])),
                'node_available' => $this->successful($path, $this->composeCommand($composeFile, ['exec', '-T', $service, 'node', '--version'])),
                'npm_available' => $this->successful($path, $this->composeCommand($composeFile, ['exec', '-T', $service, 'npm', '--version'])),
            ];
        }

        return $this->emptyApplicationCapabilities();
    }

    /** @return array{service: ?string, php_available: bool, pdo_pgsql_available: bool, composer_available: bool, node_available: bool, npm_available: bool} */
    private function emptyApplicationCapabilities(): array
    {
        return [
            'service' => null,
            'php_available' => false,
            'pdo_pgsql_available' => false,
            'composer_available' => false,
            'node_available' => false,
            'npm_available' => false,
        ];
    }

    /** @param array<string, bool> $host
     * @param  array<string, mixed>  $project
     * @return list<string>
     */
    private function guidance(array $host, array $project): array
    {
        $guidance = ['Host capability checks describe only the Ubuntu host that launches Codex; they are not proof of what is installed in project containers.'];
        $compose = $project['compose'];
        $postgresql = $project['postgresql'];
        $application = $project['application'];

        if ($compose['configured']) {
            $guidance[] = 'This managed project defines Docker Compose. Do not declare a project dependency unavailable solely because the matching host CLI or PHP extension is missing.';

            if ($host['docker_cli_available'] && $host['docker_compose_available'] && $host['docker_daemon_available']) {
                $guidance[] = 'Prefer the repository\'s existing Docker Compose or Laravel Sail conventions for project execution and verification when they are applicable.';
            } else {
                $guidance[] = 'Compose configuration exists, but Docker is not fully reachable from the AIOS host. Report Docker runtime access as unavailable instead of claiming configured container dependencies do not exist.';
            }
        } else {
            $guidance[] = 'No supported Compose file was detected at the managed project root, so host capability checks are the primary runtime evidence unless repository documentation says otherwise.';
        }

        if ($postgresql['expected_in_container']) {
            $suffix = $postgresql['service'] === null ? '' : " in service `{$postgresql['service']}`";
            $guidance[] = 'PostgreSQL is expected in the project Docker runtime'.$suffix.'. Missing host `psql` or host `pdo_pgsql` must not be reported as PostgreSQL being unavailable.';
        }

        if ($application['service'] !== null && $application['pdo_pgsql_available']) {
            $guidance[] = "PHP `pdo_pgsql` is available inside application service `{$application['service']}`; a missing host extension is not a project runtime failure.";
        }

        return $guidance;
    }
}
