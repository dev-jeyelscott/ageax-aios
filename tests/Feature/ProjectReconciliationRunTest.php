<?php

use App\Actions\RunProjectReconciliation;
use App\AgentRole;
use App\Models\AgentRun;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\ProjectReconciliationRun;
use App\ProjectReconciliationStatus;
use App\ProjectReconciliationTrigger;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use App\Services\DocumentationDriftCandidateRecorder;
use App\Services\ProjectReconciliationSnapshotBuilder;
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
        'functionality_delta' => [
            'unchanged' => [],
            'added' => [['summary' => 'Feature A', 'evidence_paths' => ['baseline.txt'], 'evidence_shas' => []]],
            'changed' => [],
            'removed' => [],
            'uncertain' => [],
        ],
        'documentation_findings' => [],
        'resolved_drift' => [],
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

test('a reconciliation run persists mechanical topology evidence and resynchronizes its manifest', function (): void {
    $project = reconciliationGitProject();
    $vault = sys_get_temp_dir().'/ageax-aios-reconciliation-vault-'.fake()->uuid();
    File::ensureDirectoryExists($vault.'/Projects/reconciliation/Roadmaps');
    File::put($vault.'/Projects/reconciliation/Roadmaps/Latest Upload.md', '# Roadmap');
    config()->set('aios.obsidian_vault_path', $vault);
    bindReconciliationHarness(['exit_code' => 0, 'output' => json_encode(validReconciliationOutput(), JSON_THROW_ON_ERROR), 'error_output' => '']);

    $run = reconciliationQueuedRun($project);
    app(RunProjectReconciliation::class)->handle($run);
    $run->refresh();

    $topologyAudit = $project->auditEvents()->where('event_type', 'reconciliation.topology_synchronized')->first();

    expect($run->mechanical_result['created'])->toContain('index.md', 'Roadmaps/index.md')
        ->and($project->knowledgeSourceManifests()->where('source_reference', 'index.md')->exists())->toBeTrue()
        ->and($topologyAudit?->payload['created'])->toContain('index.md', 'Roadmaps/index.md');

    File::deleteDirectory($vault);
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

test('the reconciliation inventory is bounded, attributes clean-head repository manifests, and classifies committed changes', function (): void {
    $project = reconciliationGitProject();
    File::put($project->path.'/AGENTS.md', '# Governance');
    File::ensureDirectoryExists($project->path.'/docs');
    File::put($project->path.'/docs/Architecture.md', '# Architecture');
    File::put($project->path.'/ignored.md', '# Ignored');
    Process::path($project->path)->run(['git', 'add', 'AGENTS.md', 'docs/Architecture.md', 'ignored.md']);
    Process::path($project->path)->run(['git', 'commit', '-m', 'Add project documentation']);
    $baseline = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());

    File::put($project->path.'/docs/Architecture.md', '# Updated architecture');
    File::put($project->path.'/docs/New.md', '# New');
    Process::path($project->path)->run(['git', 'rm', 'AGENTS.md']);
    Process::path($project->path)->run(['git', 'add', 'docs']);
    Process::path($project->path)->run(['git', 'commit', '-m', 'Update project documentation']);

    $snapshot = app(ProjectReconciliationSnapshotBuilder::class)->build($project, $baseline)['snapshot'];
    $paths = collect($snapshot['repository_documentation_inventory'])->pluck('path')->all();
    $changes = $snapshot['committed_changes_since_baseline']['changes'];

    expect($paths)->toContain('docs/Architecture.md', 'docs/New.md')
        ->not->toContain('ignored.md')
        ->and($changes)->toContain(['classification' => 'changed', 'path' => 'docs/Architecture.md'])
        ->toContain(['classification' => 'added', 'path' => 'docs/New.md'])
        ->toContain(['classification' => 'removed', 'path' => 'AGENTS.md'])
        ->and($project->knowledgeSourceManifests()->where('source_type', 'repository')->where('source_reference', 'docs/Architecture.md')->value('git_sha'))
        ->toBe($snapshot['git']['head_sha']);
});

test('documentation drift candidates deduplicate and preserve an operator decision', function (): void {
    $project = reconciliationGitProject();
    $finding = [
        'target_source' => 'AGENTS.md',
        'target_category' => 'rule',
        'evidence_paths' => ['app/Services/Example.php'],
        'evidence_shas' => ['abc123'],
        'observed_implementation' => 'The service does the current behavior.',
        'documented_claim' => 'The rule describes old behavior.',
        'reason_for_drift' => 'Committed implementation evidence conflicts with the rule.',
        'proposed_alignment' => 'Create an approved task to align the rule.',
        'confidence' => 1,
        'deterministic' => true,
        'requires_knowledge_architect_analysis' => false,
    ];

    $recorder = app(DocumentationDriftCandidateRecorder::class);
    expect($recorder->record($project, [$finding]))->toBe(1)
        ->and($recorder->record($project, [$finding]))->toBe(0);

    $candidate = KnowledgeImprovementCandidate::query()->whereBelongsTo($project)->sole();
    $candidate->update(['status' => 'approved']);
    $finding['proposed_alignment'] = 'Use the normal Task, Coder, Git, and Reviewer lifecycle.';
    $recorder->record($project, [$finding]);

    expect($candidate->refresh()->getRawOriginal('status'))->toBe('approved')
        ->and(KnowledgeImprovementCandidate::query()->whereBelongsTo($project)->count())->toBe(1);
});
