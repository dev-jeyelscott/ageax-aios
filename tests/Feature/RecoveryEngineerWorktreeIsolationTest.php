<?php

use App\Models\Project;
use App\Models\RecoveryIncident;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\Services\RecoveryEngineerRunner;
use App\Services\RecoveryRepositoryLifecycle;
use App\Services\WorkflowRecoveryEngine;
use Illuminate\Support\Facades\Process;

function isolationRepository(): string
{
    $path = sys_get_temp_dir().'/aios-recovery-isolation-'.uniqid();
    mkdir($path, 0700, true);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    file_put_contents($path.'/baseline.txt', "original\n");
    Process::path($path)->run(['git', 'add', 'baseline.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);

    return $path;
}

test('the Recovery Engineer harness never receives the live AIOS repository as its execution path', function () {
    $repositoryPath = isolationRepository();
    config()->set('aios.recovery_repository_path', $repositoryPath);

    $project = Project::create(['name' => 'Example', 'path' => sys_get_temp_dir().'/aios-recovery-project-'.uniqid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => null, 'failure_type' => 'stale_lease', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);

    $capturedPath = null;
    $mock = Mockery::mock(RecoveryEngineerRunner::class);
    $mock->shouldReceive('run')->once()->andReturnUsing(function ($agent, $prompt, $path) use (&$capturedPath, $repositoryPath): array {
        $capturedPath = $path;
        expect($path)->not->toBe($repositoryPath);
        expect(is_dir($path))->toBeTrue();

        // Simulate the harness editing a file inside the worktree it was actually granted.
        file_put_contents($path.'/baseline.txt', "changed by recovery engineer\n");

        return [
            'execution' => ['exit_code' => 0, 'output' => '{}', 'error_output' => ''],
            'decision' => [
                'root_cause_category' => 'orchestration_defect',
                'root_cause_summary' => 'A bug caused the incident.',
                'recoverable' => true,
                'fix_applied' => true,
                'changed_files' => ['baseline.txt'],
                'fix_summary' => 'Fixed it.',
            ],
        ];
    });
    app()->instance(RecoveryEngineerRunner::class, $mock);

    app(WorkflowRecoveryEngine::class)->process($incident);

    expect($capturedPath)->not->toBeNull()
        ->and(is_dir($capturedPath))->toBeFalse() // the disposable worktree is destroyed afterward
        ->and(file_get_contents($repositoryPath.'/baseline.txt'))->toBe("changed by recovery engineer\n");
});

test('a worktree creation failure blocks diagnosis and never invokes the harness', function () {
    // An unusable repository path (not a Git repository at all) makes real worktree creation fail.
    $repositoryPath = sys_get_temp_dir().'/aios-recovery-not-a-repo-'.uniqid();
    mkdir($repositoryPath, 0700, true);
    config()->set('aios.recovery_repository_path', $repositoryPath);
    Process::path($repositoryPath)->run(['git', 'init']);
    Process::path($repositoryPath)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($repositoryPath)->run(['git', 'config', 'user.name', 'AIOS Test']);
    file_put_contents($repositoryPath.'/baseline.txt', "original\n");
    Process::path($repositoryPath)->run(['git', 'add', 'baseline.txt']);
    Process::path($repositoryPath)->run(['git', 'commit', '-m', 'Baseline']);

    // Corrupt worktree creation by pointing it at an invalid base SHA the repository does not have.
    $project = Project::create(['name' => 'Example', 'path' => sys_get_temp_dir().'/aios-recovery-project-'.uniqid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'task_id' => null, 'failure_type' => 'stale_lease', 'status' => RecoveryIncidentStatus::Detected, 'detected_at' => now()]);

    $lifecycleMock = Mockery::mock(RecoveryRepositoryLifecycle::class);
    $lifecycleMock->shouldReceive('preflight')->once()->andReturn(['clean' => true, 'head_sha' => 'not-a-real-sha', 'errors' => []]);
    app()->instance(RecoveryRepositoryLifecycle::class, $lifecycleMock);

    $runnerMock = Mockery::mock(RecoveryEngineerRunner::class);
    $runnerMock->shouldNotReceive('run');
    app()->instance(RecoveryEngineerRunner::class, $runnerMock);

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->escalation_reason)->toContain('worktree');
});
