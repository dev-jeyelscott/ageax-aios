<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\ClaudeCodeCliRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ReflectionProperty;

function destructiveDenyProject(): Project
{
    $workspace = sys_get_temp_dir().'/aios-claude-deny-'.uniqid();
    mkdir($workspace, 0700, true);
    config()->set('aios.workspace_root', dirname($workspace));

    return Project::create([
        'name' => 'Deny '.uniqid(),
        'path' => $workspace,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function deniedTools(array $command): string
{
    $index = array_search('--disallowedTools', $command, true);

    return is_int($index) ? (string) ($command[$index + 1] ?? '') : '';
}

test('denies destructive database and filesystem commands for the Coder role', function () {
    $project = destructiveDenyProject();
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::Coder, 'harness' => AgentHarnessIdentifier::ClaudeCode]);
    Process::fake(['*' => Process::result(output: '')]);

    app(ClaudeCodeCliRunner::class)->run($project, $agent, 'Implement the task.');

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (new ReflectionProperty($process, 'command'))->getValue($process);
        $denied = deniedTools($command);

        return str_contains($denied, 'Bash(php artisan migrate:fresh*)')
            && str_contains($denied, 'Bash(php artisan migrate:reset*)')
            && str_contains($denied, 'Bash(php artisan db:wipe*)')
            && str_contains($denied, 'Bash(dropdb*)')
            && str_contains($denied, 'Bash(mysqladmin drop*)')
            && str_contains($denied, 'Bash(psql *DROP DATABASE*)')
            && str_contains($denied, 'Bash(rm -rf *)')
            && str_contains($denied, 'Bash(rm *.sqlite*)');
    });
});

test('denies destructive database and filesystem commands for the Recovery Engineer role, which may also edit', function () {
    $project = destructiveDenyProject();
    $agent = Agent::query()->whereNull('project_id')->where('role', AgentRole::RecoveryEngineer)->sole();
    $agent->forceFill(['harness' => AgentHarnessIdentifier::ClaudeCode])->save();
    Process::fake(['*' => Process::result(output: '')]);

    app(ClaudeCodeCliRunner::class)->run($project, $agent, 'Diagnose.');

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (new ReflectionProperty($process, 'command'))->getValue($process);
        $denied = deniedTools($command);

        return str_contains($denied, 'Bash(php artisan migrate:fresh*)')
            && str_contains($denied, 'Bash(rm -rf *)');
    });
});

test('stops Claude Code when its AIOS worker lease is lost', function () {
    $binary = tempnam(sys_get_temp_dir(), 'aios-claude-lease-');
    expect($binary)->not->toBeFalse();
    file_put_contents($binary, "#!/bin/sh\nif [ \"\$1\" = auth ]; then exit 0; fi\nsleep 5\n");
    chmod($binary, 0700);

    try {
        config()->set('aios.claude_code_binary', $binary);
        $project = destructiveDenyProject();
        $agent = Agent::factory()->for($project)->create([
            'role' => AgentRole::Coder,
            'harness' => AgentHarnessIdentifier::ClaudeCode,
        ]);

        $result = app(ClaudeCodeCliRunner::class)->run(
            $project,
            $agent,
            'Implement the task.',
            onHeartbeat: fn (): bool => false,
        );

        expect($result['exit_code'])->toBe(125)
            ->and($result['failure_type'])->toBe('worker_lease_lost');
    } finally {
        @unlink($binary);
    }
});
