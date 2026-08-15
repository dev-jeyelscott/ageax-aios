<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Throwable;

class ProjectRuntimeCapabilityDetector
{
    private const int ProbeTimeoutSeconds = 5;

    private const int MaxComposeFileBytes = 1048576;

    private const int MaxServiceCount = 100;

    private const int MaxServiceNameLength = 128;

    /** @var list<string> */
    private const array ComposeFiles = [
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
    ];

    public function __construct(private WorkspacePathResolver $paths, private Filesystem $files, private SanitizedExecutionEnvironment $environment) {}

    /** @return array<string, mixed> */
    public function detect(Project $project): array
    {
        $path = $this->paths->assertProjectPath($project->path);
        $composeFile = $this->composeFile($path);
        $composeMetadata = $composeFile === null ? [] : $this->composeMetadata($this->readProjectFile($path, $composeFile) ?? '');
        $serviceNames = array_keys($composeMetadata);
        $dockerCliAvailable = $this->executableAvailable('docker');
        $hostPsqlAvailable = $this->executableAvailable('psql');
        $dockerComposeAvailable = null;
        $dockerDaemonAvailable = null;
        $serviceDiscovery = $composeFile === null ? 'not_applicable' : 'compose_file';
        $runningServices = [];

        if ($composeFile !== null) {
            $dockerComposeAvailable = false;
            $dockerDaemonAvailable = false;

            if ($dockerCliAvailable) {
                $dockerComposeAvailable = $this->successfulProbe($path, ['docker', 'compose', 'version', '--short']);

                if ($dockerComposeAvailable) {
                    $configuredServices = $this->serviceNamesFromProbe($path, ['docker', 'compose', '-f', $composeFile, 'config', '--services']);
                    if ($configuredServices !== []) {
                        $serviceNames = $configuredServices;
                        $serviceDiscovery = 'docker_compose_config';
                    }

                    $dockerDaemonAvailable = $this->successfulProbe($path, ['docker', 'info', '--format', '{{.ServerVersion}}']);
                    if ($dockerDaemonAvailable) {
                        $runningServices = $this->serviceNamesFromProbe($path, ['docker', 'compose', '-f', $composeFile, 'ps', '--status', 'running', '--services']);
                    }
                }
            }
        }

        $serviceNames = $this->sanitizeServiceNames($serviceNames);
        $runningServices = array_values(array_intersect($serviceNames, $this->sanitizeServiceNames($runningServices)));
        $postgresServices = $this->postgresServices($serviceNames, $composeMetadata);
        $applicationServices = $this->applicationServices($serviceNames, $composeMetadata);
        $hasDockerfile = $this->projectFileExists($path, 'Dockerfile');
        $isPhpProject = $this->projectFileExists($path, 'composer.json');
        $hasArtisan = $this->projectFileExists($path, 'artisan');
        $postgresContainerRunning = $dockerDaemonAvailable === true
            ? array_intersect($postgresServices, $runningServices) !== []
            : null;
        $runtimePreference = $composeFile !== null
            ? 'docker_compose'
            : ($hasDockerfile ? 'repository_defined_docker' : 'host');

        return [
            'host' => [
                'os_family' => strtolower(PHP_OS_FAMILY),
                'os_distribution' => $this->linuxDistribution(),
                'docker_cli_available' => $dockerCliAvailable,
                'docker_compose_available' => $dockerComposeAvailable,
                'docker_daemon_available' => $dockerDaemonAvailable,
                'psql_available' => $hostPsqlAvailable,
                'php_extensions' => [
                    'pdo_pgsql' => extension_loaded('pdo_pgsql'),
                    'pgsql' => extension_loaded('pgsql'),
                ],
            ],
            'project' => [
                'uses_docker' => $composeFile !== null || $hasDockerfile,
                'uses_compose' => $composeFile !== null,
                'compose_file' => $composeFile,
                'service_names' => $serviceNames,
                'running_services' => $runningServices,
                'runtime_preference' => $runtimePreference,
            ],
            'tooling' => [
                'php_project' => $isPhpProject,
                'artisan_present' => $hasArtisan,
                'application_service_candidates' => $applicationServices,
                'php_tools_likely_containerized' => $composeFile !== null && $isPhpProject && $applicationServices !== [],
            ],
            'postgresql' => [
                'container_expected' => $postgresServices !== [],
                'container_services' => $postgresServices,
                'container_running' => $postgresContainerRunning,
                'host_psql_available' => $hostPsqlAvailable,
                'host_pdo_pgsql_available' => extension_loaded('pdo_pgsql'),
                'host_pgsql_extension_available' => extension_loaded('pgsql'),
                'availability_interpretation' => $this->postgresAvailability($postgresServices !== [], $postgresContainerRunning, $hostPsqlAvailable),
            ],
            'evidence' => [
                'source_files' => array_values(array_filter([$composeFile, $hasDockerfile ? 'Dockerfile' : null])),
                'service_discovery' => $serviceDiscovery,
                'running_service_probe' => $dockerDaemonAvailable === true ? 'docker_compose_ps' : 'not_available',
            ],
            'guidance' => $this->guidance($composeFile !== null, $postgresServices, $applicationServices),
        ];
    }

    private function composeFile(string $path): ?string
    {
        foreach (self::ComposeFiles as $filename) {
            $resolved = $this->safeProjectFile($path, $filename);
            $size = $resolved === null ? false : filesize($resolved);

            if ($resolved !== null && $size !== false && $size <= self::MaxComposeFileBytes) {
                return $filename;
            }
        }

        return null;
    }

    private function projectFileExists(string $path, string $filename): bool
    {
        return $this->safeProjectFile($path, $filename) !== null;
    }

    private function readProjectFile(string $path, string $filename): ?string
    {
        $resolved = $this->safeProjectFile($path, $filename);
        if ($resolved === null) {
            return null;
        }

        $size = filesize($resolved);
        if ($size === false || $size > self::MaxComposeFileBytes) {
            return null;
        }

        $contents = file_get_contents($resolved);

        return $contents === false ? null : $contents;
    }

    private function safeProjectFile(string $path, string $filename): ?string
    {
        $resolved = realpath($path . DIRECTORY_SEPARATOR . $filename);
        $prefix = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($resolved === false || ! str_starts_with($resolved, $prefix) || ! $this->files->isFile($resolved)) {
            return null;
        }

        return $resolved;
    }

    private function executableAvailable(string $binary): bool
    {
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $command */
    private function successfulProbe(string $path, array $command): bool
    {
        return $this->probe($path, $command)?->successful() === true;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function serviceNamesFromProbe(string $path, array $command): array
    {
        $result = $this->probe($path, $command);
        if ($result?->successful() !== true) {
            return [];
        }

        return $this->sanitizeServiceNames(preg_split('/\R/', trim($result->output())) ?: []);
    }

    /** @param list<string> $command */
    private function probe(string $path, array $command): ?ProcessResult
    {
        try {
            return Process::path($path)
                ->timeout(self::ProbeTimeoutSeconds)
                ->run($this->environment->wrap($command));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $services
     * @return list<string>
     */
    private function sanitizeServiceNames(array $services): array
    {
        $sanitized = array_values(array_unique(array_filter(
            array_map('trim', $services),
            fn(string $service): bool => strlen($service) <= self::MaxServiceNameLength
                && preg_match('/^[A-Za-z0-9_.-]+$/', $service) === 1,
        )));

        return array_slice($sanitized, 0, self::MaxServiceCount);
    }

    /** @return array<string, array{image: ?string}> */
    private function composeMetadata(string $contents): array
    {
        $metadata = [];
        $servicesIndent = null;
        $serviceIndent = null;
        $currentService = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if ($servicesIndent === null) {
                if (preg_match('/^(\s*)services\s*:\s*(?:#.*)?$/', $line, $matches) === 1) {
                    $servicesIndent = strlen($matches[1]);
                }

                continue;
            }

            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            preg_match('/^(\s*)/', $line, $indentMatch);
            $indent = strlen($indentMatch[1] ?? '');
            if ($indent <= $servicesIndent) {
                break;
            }

            $service = $this->serviceNameFromLine($line);
            if ($serviceIndent === null && $service !== null) {
                $serviceIndent = $indent;
            }

            if ($serviceIndent !== null && $indent === $serviceIndent && $service !== null) {
                $currentService = $service;
                $metadata[$currentService] = ['image' => null];

                continue;
            }

            if ($currentService === null || $serviceIndent === null || $indent <= $serviceIndent) {
                continue;
            }

            if (preg_match('/^\s*image\s*:\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s#]+))/', $line, $matches) === 1) {
                $metadata[$currentService]['image'] = $matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : $matches[3]);
            }
        }

        return $metadata;
    }

    private function serviceNameFromLine(string $line): ?string
    {
        if (preg_match('/^\s+(?:"([^"]+)"|\'([^\']+)\'|([A-Za-z0-9_.-]+))\s*:\s*(?:#.*)?$/', $line, $matches) !== 1) {
            return null;
        }

        $service = $matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : $matches[3]);

        return preg_match('/^[A-Za-z0-9_.-]+$/', $service) === 1 ? $service : null;
    }

    /**
     * @param  list<string>  $serviceNames
     * @param  array<string, array{image: ?string}>  $metadata
     * @return list<string>
     */
    private function postgresServices(array $serviceNames, array $metadata): array
    {
        return array_values(array_filter($serviceNames, function (string $service) use ($metadata): bool {
            $image = $metadata[$service]['image'] ?? null;

            return preg_match('/(?:^|[-_.])(postgres|postgresql|pgsql)(?:$|[-_.])/i', $service) === 1
                || (is_string($image) && preg_match('/(?:postgres|postgresql|timescale)/i', $image) === 1);
        }));
    }

    /**
     * @param  list<string>  $serviceNames
     * @param  array<string, array{image: ?string}>  $metadata
     * @return list<string>
     */
    private function applicationServices(array $serviceNames, array $metadata): array
    {
        return array_values(array_filter($serviceNames, function (string $service) use ($metadata): bool {
            $image = $metadata[$service]['image'] ?? null;

            return preg_match('/(?:^|[-_.])(app|php|api|backend|laravel)(?:$|[-_.])/i', $service) === 1
                || (is_string($image) && preg_match('/(?:^|[\/:_-])php(?::|@|$)/i', $image) === 1);
        }));
    }

    private function postgresAvailability(bool $containerExpected, ?bool $containerRunning, bool $hostPsqlAvailable): string
    {
        if ($containerRunning === true) {
            return 'available_via_running_container';
        }

        if ($containerExpected && $containerRunning === false) {
            return 'configured_but_not_running';
        }

        if ($containerExpected) {
            return 'configured_for_container_runtime_unverified';
        }

        if ($hostPsqlAvailable || extension_loaded('pdo_pgsql') || extension_loaded('pgsql')) {
            return 'host_capability_detected';
        }

        return 'not_detected';
    }

    /**
     * @param  list<string>  $postgresServices
     * @param  list<string>  $applicationServices
     * @return list<string>
     */
    private function guidance(bool $usesCompose, array $postgresServices, array $applicationServices): array
    {
        if (! $usesCompose) {
            return [
                'No Docker Compose runtime was detected. Follow repository-defined runtime conventions and host tooling; do not invent container services.',
                'Do not inspect or expose .env contents, credentials, or secrets solely to determine runtime capabilities.',
            ];
        }

        $guidance = [
            'Treat host tool and PHP-extension availability as host-only evidence; missing host tools do not override capabilities configured in Docker Compose.',
            'Prefer the project\'s existing Docker Compose services for project tooling and verification when the repository is container-managed.',
            'Do not inspect or expose .env contents, Compose environment values, credentials, or secrets solely to determine runtime capabilities.',
        ];

        if ($postgresServices !== []) {
            $guidance[] = 'PostgreSQL is configured in Docker Compose. Use the listed PostgreSQL service for database capability checks; do not declare PostgreSQL unavailable solely because host psql, pdo_pgsql, or pgsql is missing.';
        }

        if ($applicationServices !== []) {
            $guidance[] = 'For application verification, prefer docker compose exec -T <service> ... with a listed application service candidate when that matches repository conventions.';
        }

        return $guidance;
    }

    private function linuxDistribution(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_readable('/etc/os-release')) {
            return null;
        }

        $contents = file_get_contents('/etc/os-release');
        if ($contents === false || preg_match('/^PRETTY_NAME=(?:"([^"]+)"|([^\r\n]+))$/m', $contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1] !== '' ? $matches[1] : $matches[2]);
    }
}
