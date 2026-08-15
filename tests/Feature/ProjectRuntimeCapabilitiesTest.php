<?php

use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\ProjectRuntimeCapabilities;
use App\Services\TaskContextCapsuleFactory;
use App\TaskStatus;
use Illuminate\Support\Facades\File;

function runtimeCapabilityProjectPath(): string
{
    $path = sys_get_temp_dir().'/aios-runtime-'.fake()->uuid();
    File::ensureDirectoryExists($path);

    return $path;
}

/** @param callable(): void $callback */
function withRuntimeCapabilityPath(string $path, callable $callback): void
{
    $originalPath = getenv('PATH');
    putenv('PATH='.$path);

    try {
        $callback();
    } finally {
        if ($originalPath === false) {
            putenv('PATH');
        } else {
            putenv('PATH='.$originalPath);
        }
    }
}

function installRuntimeCapabilityDockerStub(string $binPath): void
{
    File::ensureDirectoryExists($binPath);
    $script = <<<'SH'
#!/bin/sh
if [ -n "${AIOS_TEST_SECRET:-}" ]; then
    exit 77
fi

case "$*" in
    "compose version") exit 0 ;;
    "info --format {{.ServerVersion}}") printf '%s\n' '27.5.1'; exit 0 ;;
    "compose -f compose.yaml config --services --no-interpolate --no-env-resolution") printf '%s\n' 'app' 'postgres'; exit 0 ;;
    "compose -f compose.yaml config --images --no-interpolate --no-env-resolution") printf '%s\n' 'example-app' 'postgres:17'; exit 0 ;;
    "compose -f compose.yaml ps --services --status running") printf '%s\n' 'app' 'postgres'; exit 0 ;;
    "compose -f compose.yaml ps --all --format json") printf '%s\n' '[{"Service":"app","Image":"example-app","State":"running"},{"Service":"postgres","Image":"postgres:17","State":"running"}]'; exit 0 ;;
    "compose -f compose.yaml exec -T postgres psql --version") printf '%s\n' 'psql (PostgreSQL) 17'; exit 0 ;;
    "compose -f compose.yaml exec -T app php -v") exit 0 ;;
    "compose -f compose.yaml exec -T app php -r "*) exit 0 ;;
    "compose -f compose.yaml exec -T app composer --version --no-ansi") exit 0 ;;
    "compose -f compose.yaml exec -T app node --version") exit 1 ;;
    "compose -f compose.yaml exec -T app npm --version") exit 1 ;;
esac

exit 1
SH;

    File::put($binPath.'/docker', $script);
    chmod($binPath.'/docker', 0755);
}

test('detects dockerized PostgreSQL without requiring host psql or pdo_pgsql', function () {
    $projectPath = runtimeCapabilityProjectPath();
    File::put($projectPath.'/compose.yaml', "services:\n  app:\n    image: example-app\n  postgres:\n    image: postgres:17\n");
    $binPath = $projectPath.'/.test-bin';
    installRuntimeCapabilityDockerStub($binPath);
    putenv('AIOS_TEST_SECRET=do-not-leak');

    try {
        withRuntimeCapabilityPath($binPath, function () use ($projectPath): void {
            $capabilities = app(ProjectRuntimeCapabilities::class)->inspect($projectPath);

            expect($capabilities['host'])->toMatchArray([
                'docker_cli_available' => true,
                'docker_compose_available' => true,
                'docker_daemon_available' => true,
                'psql_available' => false,
                'php_available' => false,
                'pdo_pgsql_available' => false,
            ])->and($capabilities['project']['compose'])->toMatchArray([
                'configured' => true,
                'file' => 'compose.yaml',
                'services' => ['app', 'postgres'],
                'running_services' => ['app', 'postgres'],
            ])->and($capabilities['project']['postgresql'])->toMatchArray([
                'expected_in_container' => true,
                'service' => 'postgres',
                'running' => true,
                'psql_service' => 'postgres',
            ])->and($capabilities['project']['application'])->toMatchArray([
                'service' => 'app',
                'php_available' => true,
                'pdo_pgsql_available' => true,
                'composer_available' => true,
            ])->and(implode(' ', $capabilities['guidance']))->toContain('Missing host `psql` or host `pdo_pgsql` must not be reported as PostgreSQL being unavailable.')
                ->and(json_encode($capabilities, JSON_THROW_ON_ERROR))->not->toContain('do-not-leak');
        });
    } finally {
        putenv('AIOS_TEST_SECRET');
        File::deleteDirectory($projectPath);
    }
});

test('reports non Docker projects without inventing container capabilities', function () {
    $projectPath = runtimeCapabilityProjectPath();

    try {
        $capabilities = app(ProjectRuntimeCapabilities::class)->inspect($projectPath);

        expect($capabilities['project']['compose'])->toMatchArray([
            'configured' => false,
            'file' => null,
            'services' => [],
            'running_services' => [],
        ])->and($capabilities['project']['postgresql'])->toMatchArray([
            'expected_in_container' => false,
            'service' => null,
            'running' => false,
        ])->and(implode(' ', $capabilities['guidance']))->toContain('No supported Compose file was detected');
    } finally {
        File::deleteDirectory($projectPath);
    }
});

test('reports Docker runtime access separately when Compose is configured but Docker is unavailable', function () {
    $projectPath = runtimeCapabilityProjectPath();
    File::put($projectPath.'/compose.yaml', "services:\n  postgres:\n    image: postgres:17\n");
    $emptyBinPath = $projectPath.'/.empty-bin';
    File::ensureDirectoryExists($emptyBinPath);

    try {
        withRuntimeCapabilityPath($emptyBinPath, function () use ($projectPath): void {
            $capabilities = app(ProjectRuntimeCapabilities::class)->inspect($projectPath);

            expect($capabilities['host'])->toMatchArray([
                'docker_cli_available' => false,
                'docker_compose_available' => false,
                'docker_daemon_available' => false,
            ])->and($capabilities['project']['compose'])->toMatchArray([
                'configured' => true,
                'file' => 'compose.yaml',
                'services' => [],
                'running_services' => [],
            ])->and($capabilities['project']['postgresql']['expected_in_container'])->toBeTrue()
                ->and(implode(' ', $capabilities['guidance']))->toContain('Report Docker runtime access as unavailable instead of claiming configured container dependencies do not exist.');
        });
    } finally {
        File::deleteDirectory($projectPath);
    }
});

test('task capsules carry safe runtime capability context for coder and reviewer', function () {
    $projectPath = runtimeCapabilityProjectPath();

    try {
        $project = Project::create(['name' => 'Runtime context', 'path' => $projectPath, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
        $task = Task::create([
            'project_id' => $project->id,
            'key' => 'TASK-001',
            'position' => 1,
            'title' => 'Use the project runtime',
            'objective' => 'Use runtime evidence.',
            'acceptance_criteria' => ['Runtime context is available.'],
            'implementation_prompt' => 'Inspect the runtime context.',
            'context_capsule' => [],
            'status' => TaskStatus::Reviewing,
        ]);

        $coder = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Coder);
        $reviewer = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Reviewer);

        expect($coder['runtime_capabilities']['project']['compose']['configured'])->toBeFalse()
            ->and($reviewer['runtime_capabilities']['project']['compose']['configured'])->toBeFalse()
            ->and($project->auditEvents()->where('event_type', 'runtime.capabilities_detected')->count())->toBe(2);
    } finally {
        File::deleteDirectory($projectPath);
    }
});
