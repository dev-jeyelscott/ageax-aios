<?php

use App\Actions\RunProjectReconciliation;
use App\AgentRole;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\ProjectReconciliationRun;
use App\ProjectReconciliationStatus;
use App\ProjectReconciliationTrigger;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function reconciliationGitProject(): Project
{
    $path = sys_get_temp_dir().'/aios-reconciliation-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/baseline.txt', 'baseline');
    Process::path($path)->run(['git', 'add', 'baseline.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);

    return Project::create(['name' => 'Reconciliation', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
}

function reconciliationQueuedRun(Project $project): ProjectReconciliationRun
{
    return ProjectReconciliationRun::create([
        'project_id' => $project->id,
        'trigger' => ProjectReconciliationTrigger::Manual,
        'status' => ProjectReconciliationStatus::Queued,
    ]);
}

/** @param array{exit_code: int, output: string, error_output: string} $execution */
function bindReconciliationHarness(array $execution): void
{
    app()->bind(CodexCliRunner::class, function () use ($execution): CodexCliRunner {
        return new class($execution) extends CodexCliRunner
        {
            /** @param array{exit_code: int, output: string, error_output: string} $execution */
            public function __construct(private array $execution) {}

            public function run(Project $project, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null): array
            {
                return $this->execution;
            }
        };
    });
}

/** @return array<string, mixed> */
function validReconciliationOutput(): array
{
    return [
        'project_status' => 'Healthy',
        'functionality_summary' => 'Everything works.',
        'new_functionality' => ['Feature A'],
        'changed_functionality' => [],
        'removed_functionality' => [],
        'documentation_drift' => [],
        'obsidian_findings' => [],
        'risks' => [],
        'recommended_actions' => [],
    ];
}

test('a first reconciliation run establishes the baseline and persists a completed advisory result', function () {
    $project = reconciliationGitProject();
    bindReconciliationHarness(['exit_code' => 0, 'output' => json_encode(validReconciliationOutput(), JSON_THROW_ON_ERROR), 'error_output' => '']);

    $run = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($run);
    $run->refresh();

    expect($run->status)->toBe(ProjectReconciliationStatus::Completed)
        ->and($run->baseline_sha)->toBeNull()
        ->and($run->evaluated_head_sha)->not->toBeNull()
        ->and($run->snapshot_hash)->not->toBeNull()
        ->and($run->working_tree_dirty)->toBeFalse()
        ->and($run->agent_run_id)->not->toBeNull()
        ->and($run->result['project_status'])->toBe('Healthy');

    $agentRun = AgentRun::find($run->agent_run_id);
    expect($agentRun->role)->toBe(AgentRole::ProjectManager)
        ->and($agentRun->project_id)->toBe($project->id);
});

test('a second run with nothing changed is skipped without invoking the harness again', function () {
    $project = reconciliationGitProject();
    bindReconciliationHarness(['exit_code' => 0, 'output' => json_encode(validReconciliationOutput(), JSON_THROW_ON_ERROR), 'error_output' => '']);

    $firstRun = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($firstRun);
    $firstRun->refresh();

    $secondRun = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($secondRun);
    $secondRun->refresh();

    expect($secondRun->status)->toBe(ProjectReconciliationStatus::SkippedNoChange)
        ->and($secondRun->agent_run_id)->toBeNull()
        ->and($secondRun->baseline_sha)->toBe($firstRun->evaluated_head_sha)
        ->and(AgentRun::query()->count())->toBe(1);
});

test('a dirty working tree is recorded without changing the evaluated commit', function () {
    $project = reconciliationGitProject();
    bindReconciliationHarness(['exit_code' => 0, 'output' => json_encode(validReconciliationOutput(), JSON_THROW_ON_ERROR), 'error_output' => '']);

    $firstRun = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($firstRun);
    $firstRun->refresh();

    File::put($project->path.'/uncommitted.txt', 'not committed');

    $secondRun = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($secondRun);
    $secondRun->refresh();

    expect($secondRun->working_tree_dirty)->toBeTrue()
        ->and($secondRun->evaluated_head_sha)->toBe($firstRun->evaluated_head_sha);
});

test('the Project Manager reconciliation run never mutates the managed repository', function () {
    $project = reconciliationGitProject();
    bindReconciliationHarness(['exit_code' => 0, 'output' => json_encode(validReconciliationOutput(), JSON_THROW_ON_ERROR), 'error_output' => '']);

    $beforeNames = collect(File::allFiles($project->path))->map->getRelativePathname()->sort()->values()->all();

    $run = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($run);

    $afterNames = collect(File::allFiles($project->path))->map->getRelativePathname()->sort()->values()->all();

    expect($afterNames)->toBe($beforeNames);
});

test('a non-zero harness exit code fails the run with a recorded reason', function () {
    $project = reconciliationGitProject();
    bindReconciliationHarness(['exit_code' => 1, 'output' => '', 'error_output' => 'boom']);

    $run = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($run);
    $run->refresh();

    expect($run->status)->toBe(ProjectReconciliationStatus::Failed)
        ->and($run->failure_reason)->toBe('boom')
        ->and($project->auditEvents()->where('event_type', 'reconciliation.failed')->exists())->toBeTrue();
});

test('malformed structured output fails the run', function () {
    $project = reconciliationGitProject();
    bindReconciliationHarness(['exit_code' => 0, 'output' => 'not json', 'error_output' => '']);

    $run = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($run);
    $run->refresh();

    expect($run->status)->toBe(ProjectReconciliationStatus::Failed);
});

test('a schema-invalid structured result fails the run without persisting a partial result', function () {
    $project = reconciliationGitProject();
    bindReconciliationHarness(['exit_code' => 0, 'output' => json_encode(['project_status' => 'Healthy'], JSON_THROW_ON_ERROR), 'error_output' => '']);

    $run = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($run);
    $run->refresh();

    expect($run->status)->toBe(ProjectReconciliationStatus::Failed)
        ->and($run->result)->toBeNull();
});
