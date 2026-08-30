<?php

use App\AgentRole;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Agent;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ReflectionProperty;

test('runs Codex with an explicitly isolated environment', function () {
    $originalDbPassword = getenv('DB_PASSWORD');
    $originalApiToken = getenv('AIOS_TEST_API_TOKEN');
    putenv('DB_PASSWORD=must-not-reach-codex');
    putenv('AIOS_TEST_API_TOKEN=must-not-reach-codex');
    Process::fake(['*' => Process::result(output: 'completed')]);

    try {
        app(CodexCliRunner::class)->runAtPath(base_path(), 'Implement the task.');

        Process::assertRan(function (PendingProcess $process): bool {
            $command = (new ReflectionProperty($process, 'command'))->getValue($process);

            return $command[0] === '/usr/bin/env'
                && $command[1] === '-i'
                && in_array('HOME='.getenv('HOME'), $command, true)
                && in_array('PATH='.getenv('PATH'), $command, true)
                && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'DB_'))
                && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'AIOS_TEST_API_TOKEN='))
                && in_array(config('aios.codex_binary'), $command, true);
        });
    } finally {
        $originalDbPassword === false ? putenv('DB_PASSWORD') : putenv('DB_PASSWORD='.$originalDbPassword);
        $originalApiToken === false ? putenv('AIOS_TEST_API_TOKEN') : putenv('AIOS_TEST_API_TOKEN='.$originalApiToken);
    }
});

test('allows the Knowledge Architect to run in its AIOS-created non-Git advisory workspace', function () {
    $agent = Agent::factory()->make([
        'project_id' => null,
        'role' => AgentRole::KnowledgeArchitect,
    ]);

    $sandbox = sys_get_temp_dir().'/aios-non-git-sandbox-'.uniqid();
    mkdir($sandbox, recursive: true);

    Process::fake(['*' => Process::result(output: 'completed')]);

    app(CodexCliRunner::class)->runAtPath(
        $sandbox,
        'Analyze the supplied advisory evidence.',
        agent: $agent,
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (new ReflectionProperty($process, 'command'))->getValue($process);

        return in_array('--skip-git-repo-check', $command, true);
    });
});

test('allows a Project Manager reconciliation advisory to run in its AIOS-created non-Git sandbox', function () {
    $agent = Agent::factory()->make([
        'project_id' => null,
        'role' => AgentRole::ProjectManager,
    ]);

    $sandbox = sys_get_temp_dir().'/aios-non-git-sandbox-'.uniqid();
    mkdir($sandbox, recursive: true);

    Process::fake(['*' => Process::result(output: 'completed')]);

    app(CodexCliRunner::class)->runAtPath(
        $sandbox,
        'Produce a read-only reconciliation advisory.',
        agent: $agent,
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (new ReflectionProperty($process, 'command'))->getValue($process);

        return in_array('--skip-git-repo-check', $command, true);
    });
});

test('does not skip the Git repo check for a real managed project repository', function () {
    $agent = Agent::factory()->make([
        'project_id' => null,
        'role' => AgentRole::Coder,
    ]);

    Process::fake(['*' => Process::result(output: 'completed')]);

    app(CodexCliRunner::class)->runAtPath(
        base_path(),
        'Implement the task.',
        agent: $agent,
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (new ReflectionProperty($process, 'command'))->getValue($process);

        return ! in_array('--skip-git-repo-check', $command, true);
    });
});

test('refuses to run Codex for a persisted project path outside the workspace', function () {
    config()->set('aios.workspace_root', sys_get_temp_dir());
    $project = Project::create(['name' => 'Unsafe', 'path' => base_path(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    Process::fake();

    expect(fn () => app(CodexCliRunner::class)->run($project, 'Implement the task.'))->toThrow(UnsafeProjectPath::class);
    Process::assertNotRan(fn (): bool => true);
});

test('converts an execution timeout into a normalized failure result instead of throwing', function () {
    $binary = tempnam(sys_get_temp_dir(), 'aios-codex-timeout-');
    expect($binary)->not->toBeFalse();
    file_put_contents($binary, "#!/bin/sh\nsleep 2\n");
    chmod($binary, 0700);

    try {
        config()->set('aios.codex_binary', $binary);
        config()->set('aios.execution_timeout', 1);

        $result = app(CodexCliRunner::class)->runAtPath(base_path(), 'Implement the task.');

        expect($result['exit_code'])->toBe(124)
            ->and($result['error_output'])->toContain('execution timeout')
            ->and($result['output'])->toBe('');
    } finally {
        @unlink($binary);
    }
});

test('uses the persisted AIOS execution setting when supplied', function () {
    Process::fake(['*' => Process::result(output: 'completed')]);

    app(CodexCliRunner::class)->runAtPath(
        base_path(),
        'Implement the task.',
        executionSettings: ['max_execution_seconds' => 180],
    );

    Process::assertRan(function (PendingProcess $process): bool {
        return $process->timeout === 180;
    });
});

test('resumes only the AIOS-supplied durable Goal session without enabling unrestricted execution', function () {
    Process::fake(['*' => Process::result(output: 'completed')]);

    app(CodexCliRunner::class)->runAtPath(
        base_path(),
        'Continue the approved feature goal.',
        executionSettings: ['provider_session_id' => 'goal-session-id'],
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (new ReflectionProperty($process, 'command'))->getValue($process);

        return in_array('resume', $command, true)
            && in_array('goal-session-id', $command, true)
            && ! in_array('--ephemeral', $command, true)
            && ! in_array('--approve-for-me', $command, true)
            && ! in_array('--dangerously-bypass-approvals-and-sandbox', $command, true);
    });
});

test('converts an externally signaled process into a normalized failure result instead of throwing', function () {
    $binary = tempnam(sys_get_temp_dir(), 'aios-codex-signaled-');
    expect($binary)->not->toBeFalse();
    file_put_contents($binary, "#!/bin/sh\necho partial-output\nkill -TERM \$\$\nsleep 5\n");
    chmod($binary, 0700);

    try {
        config()->set('aios.codex_binary', $binary);

        $result = app(CodexCliRunner::class)->runAtPath(base_path(), 'Implement the task.');

        expect($result['exit_code'])->not->toBe(0)
            ->and($result['output'])->toContain('partial-output');
    } finally {
        @unlink($binary);
    }
});

test('stops Codex when its AIOS worker lease is lost', function () {
    $binary = tempnam(sys_get_temp_dir(), 'aios-codex-lease-loss-');
    expect($binary)->not->toBeFalse();
    file_put_contents($binary, "#!/bin/sh\nsleep 10\n");
    chmod($binary, 0700);

    try {
        config()->set('aios.codex_binary', $binary);
        config()->set('aios.execution_timeout', 15);

        $result = app(CodexCliRunner::class)->runAtPath(
            base_path(),
            'Implement the task.',
            onHeartbeat: fn (): bool => false,
        );

        expect($result['exit_code'])->toBe(125)
            ->and($result['error_output'])->toContain('worker lease was lost');
    } finally {
        @unlink($binary);
    }
});
