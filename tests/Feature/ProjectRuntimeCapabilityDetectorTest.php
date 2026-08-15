<?php

use App\Actions\RunProjectManager;
use App\AgentRole;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use App\Services\ProjectRuntimeCapabilityDetector;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskValidator;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\mock;

function runtimeCapabilityProject(): Project
{
    $path = sys_get_temp_dir().'/aios-runtime-'.fake()->uuid();
    File::ensureDirectoryExists($path);

    return Project::create([
        'name' => 'Runtime Example',
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function runtimeCapabilityTask(Project $project, TaskStatus $status = TaskStatus::Coding): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Runtime task',
        'objective' => 'Verify runtime discovery.',
        'acceptance_criteria' => ['Runtime capabilities are accurate.'],
        'implementation_prompt' => 'Implement the runtime fix.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

function withRuntimeCapabilityPath(array $executables, Closure $callback): mixed
{
    $originalPath = getenv('PATH');
    $binPath = sys_get_temp_dir().'/aios-runtime-bin-'.fake()->uuid();
    File::ensureDirectoryExists($binPath);

    foreach ($executables as $executable) {
        File::put($binPath.'/'.$executable, "#!/bin/sh\nexit 0\n");
        chmod($binPath.'/'.$executable, 0755);
    }

    putenv('PATH='.$binPath);

    try {
        return $callback();
    } finally {
        $originalPath === false ? putenv('PATH') : putenv('PATH='.$originalPath);
        File::deleteDirectory($binPath);
    }
}

test('detects a running dockerized PostgreSQL runtime without relying on host psql', function () {
    $project = runtimeCapabilityProject();
    File::put($project->path.'/composer.json', '{}');
    File::put($project->path.'/artisan', '#!/usr/bin/env php');
    File::put($project->path.'/compose.yaml', <<<'YAML'
services:
  app:
    image: php:8.5-cli
  db:
    image: postgres:18
    environment:
      POSTGRES_PASSWORD: super-secret-value
YAML);

    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '2.39.2'))
        ->push(Process::result(output: "app\ndb\n"))
        ->push(Process::result(output: '28.4'))
        ->push(Process::result(output: "app\ndb\n")),
    ]);

    $context = withRuntimeCapabilityPath(['docker'], fn () => app(ProjectRuntimeCapabilityDetector::class)->detect($project));

    expect($context['host']['docker_cli_available'])->toBeTrue()
        ->and($context['host']['docker_compose_available'])->toBeTrue()
        ->and($context['host']['docker_daemon_available'])->toBeTrue()
        ->and($context['host']['psql_available'])->toBeFalse()
        ->and($context['project']['uses_compose'])->toBeTrue()
        ->and($context['project']['service_names'])->toBe(['app', 'db'])
        ->and($context['project']['running_services'])->toBe(['app', 'db'])
        ->and($context['project']['runtime_preference'])->toBe('docker_compose')
        ->and($context['tooling']['application_service_candidates'])->toBe(['app'])
        ->and($context['tooling']['php_tools_likely_containerized'])->toBeTrue()
        ->and($context['postgresql']['container_expected'])->toBeTrue()
        ->and($context['postgresql']['container_services'])->toBe(['db'])
        ->and($context['postgresql']['container_running'])->toBeTrue()
        ->and($context['postgresql']['availability_interpretation'])->toBe('available_via_running_container')
        ->and(json_encode($context, JSON_THROW_ON_ERROR))->not->toContain('super-secret-value');
});

test('reports a non-Docker project without inventing container capabilities', function () {
    $project = runtimeCapabilityProject();
    File::put($project->path.'/composer.json', '{}');
    Process::fake();

    $context = withRuntimeCapabilityPath([], fn () => app(ProjectRuntimeCapabilityDetector::class)->detect($project));

    expect($context['host']['docker_cli_available'])->toBeFalse()
        ->and($context['host']['docker_compose_available'])->toBeNull()
        ->and($context['project']['uses_docker'])->toBeFalse()
        ->and($context['project']['uses_compose'])->toBeFalse()
        ->and($context['project']['service_names'])->toBe([])
        ->and($context['project']['runtime_preference'])->toBe('host')
        ->and($context['postgresql']['container_expected'])->toBeFalse();
    Process::assertNotRan(fn (): bool => true);
});

test('uses repository evidence when Docker is unavailable for a Compose project', function () {
    $project = runtimeCapabilityProject();
    File::put($project->path.'/compose.yaml', <<<'YAML'
services:
  app:
    image: php:8.5-cli
  postgres:
    image: postgres:18
YAML);
    Process::fake();

    $context = withRuntimeCapabilityPath([], fn () => app(ProjectRuntimeCapabilityDetector::class)->detect($project));

    expect($context['host']['docker_cli_available'])->toBeFalse()
        ->and($context['host']['docker_compose_available'])->toBeFalse()
        ->and($context['host']['docker_daemon_available'])->toBeFalse()
        ->and($context['project']['uses_compose'])->toBeTrue()
        ->and($context['project']['service_names'])->toBe(['app', 'postgres'])
        ->and($context['postgresql']['container_expected'])->toBeTrue()
        ->and($context['postgresql']['container_services'])->toBe(['postgres'])
        ->and($context['postgresql']['container_running'])->toBeNull()
        ->and($context['postgresql']['availability_interpretation'])->toBe('configured_for_container_runtime_unverified');
    Process::assertNotRan(fn (): bool => true);
});

test('capability context never includes Compose or dotenv secret values', function () {
    $project = runtimeCapabilityProject();
    File::put($project->path.'/.env', "DB_PASSWORD=dotenv-secret-value\n");
    File::put($project->path.'/compose.yaml', <<<'YAML'
services:
  app:
    image: php:8.5-cli
    environment:
      API_TOKEN: compose-secret-value
  db:
    image: postgres:18
    environment:
      POSTGRES_PASSWORD: ${DB_PASSWORD}
YAML);
    Process::fake();

    $context = withRuntimeCapabilityPath([], fn () => app(ProjectRuntimeCapabilityDetector::class)->detect($project));
    $encoded = json_encode($context, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('dotenv-secret-value')
        ->and($encoded)->not->toContain('compose-secret-value')
        ->and($encoded)->not->toContain('${DB_PASSWORD}')
        ->and($context['evidence']['source_files'])->toBe(['compose.yaml']);
});

test('Coder and Reviewer capsules receive the same project runtime topology', function () {
    $project = runtimeCapabilityProject();
    File::put($project->path.'/compose.yaml', <<<'YAML'
services:
  app:
    image: php:8.5-cli
  db:
    image: postgres:18
YAML);
    $task = runtimeCapabilityTask($project);
    Process::fake();

    [$coder, $reviewer] = withRuntimeCapabilityPath([], function () use ($task): array {
        $capsules = app(TaskContextCapsuleFactory::class);

        return [
            $capsules->make($task, AgentRole::Coder),
            $capsules->make($task, AgentRole::Reviewer),
        ];
    });

    expect($coder['project_runtime_capabilities'])->toBe($reviewer['project_runtime_capabilities'])
        ->and($coder['project_runtime_capabilities']['postgresql']['container_expected'])->toBeTrue()
        ->and($reviewer['project_runtime_capabilities']['project']['runtime_preference'])->toBe('docker_compose');
});

test('Project Manager receives project runtime capabilities before planning', function () {
    $project = runtimeCapabilityProject();
    File::put($project->path.'/compose.yaml', <<<'YAML'
services:
  app:
    image: php:8.5-cli
  db:
    image: postgres:18
YAML);
    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/runtime.md',
        'status' => 'uploaded',
        'content' => 'Implement the runtime-aware task.',
    ]);
    Process::fake();

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->withArgs(fn (Project $runProject, string $prompt, mixed ...$unused): bool => $runProject->is($project)
            && str_contains($prompt, 'project_runtime_capabilities')
            && str_contains($prompt, '"runtime_preference":"docker_compose"')
            && str_contains($prompt, 'host-only tool or PHP-extension absence does not mean a project capability is unavailable'))
        ->andReturn(['exit_code' => 1, 'output' => '', 'error_output' => 'stop after prompt verification']);

    withRuntimeCapabilityPath([], fn () => app(RunProjectManager::class)->handle($roadmap));
});

test('TaskValidator allows safe Docker Compose verification and rejects lifecycle mutation', function () {
    $project = runtimeCapabilityProject();
    $task = runtimeCapabilityTask($project);
    $task->update(['verification_commands' => ['docker compose exec -T app php artisan test --compact']]);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result())
        ->push(Process::result()),
    ]);

    $allowed = app(TaskValidator::class)->validate($task);

    expect($allowed['passed'])->toBeTrue()
        ->and($allowed['checks']['task_verification'])->toBeTrue();

    $task->update(['verification_commands' => ['docker compose down']]);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result()),
    ]);

    $blocked = app(TaskValidator::class)->validate($task);

    expect($blocked['passed'])->toBeFalse()
        ->and($blocked['checks']['task_verification'])->toBeFalse();
});
