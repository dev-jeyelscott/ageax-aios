<?php

namespace App\Actions;

use App\AgentRole;
use App\Exceptions\AgentNotBoundToRole;
use App\Models\Agent;
use App\Models\Project;
use App\Models\ProjectReconciliationRun;
use App\ProjectReconciliationStatus;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\AgentResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\DatabaseProtectionGuard;
use App\Services\ObsidianKnowledgeTopologySynchronizer;
use App\Services\ProjectReconciliationSnapshotBuilder;
use App\Services\StructuredResultParser;
use App\Services\WorkspacePathResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class RunProjectReconciliation
{
    public function __construct(
        private CodexCliRunner $runner,
        private AgentResolver $agents,
        private AgentHarnessResolver $harnesses,
        private AgentContextAssembler $contextAssembler,
        private AgentRunRecorder $runs,
        private StructuredResultParser $parser,
        private RecordProjectReconciliationResult $results,
        private ProjectReconciliationSnapshotBuilder $snapshots,
        private ObsidianKnowledgeTopologySynchronizer $topology,
        private WorkspacePathResolver $paths,
        private Filesystem $files,
        private DatabaseProtectionGuard $databaseProtection,
        private AuditLogger $audit,
    ) {}

    public function handle(ProjectReconciliationRun $run): void
    {
        $run = $this->claim($run);

        if ($run === null) {
            return;
        }

        $project = $run->project;
        $this->paths->assertProjectPath($project->path);

        try {
            $mechanicalResult = $this->topology->sync($project);
            $run->update(['mechanical_result' => $mechanicalResult]);
            $this->audit->record('reconciliation.topology_synchronized', [
                'reconciliation_run_id' => $run->id,
                'created_count' => count($mechanicalResult['created']),
                'changed_count' => count($mechanicalResult['changed']),
                'created' => $mechanicalResult['created'],
                'changed' => $mechanicalResult['changed'],
            ], $project);
        } catch (Throwable $throwable) {
            $this->fail($run, 'Obsidian topology synchronization failed: '.$throwable->getMessage());

            return;
        }

        $baselineRun = ProjectReconciliationRun::query()
            ->whereBelongsTo($project)
            ->whereKeyNot($run->id)
            ->whereIn('status', [ProjectReconciliationStatus::Completed->value, ProjectReconciliationStatus::SkippedNoChange->value])
            ->latest('id')
            ->first();

        $baselineSha = $baselineRun?->evaluated_head_sha;

        $snapshotResult = $this->snapshots->build($project, $baselineSha);

        $run->update([
            'baseline_sha' => $baselineSha,
            'evaluated_head_sha' => $snapshotResult['head_sha'],
            'snapshot_hash' => $snapshotResult['snapshot_hash'],
            'working_tree_dirty' => $snapshotResult['working_tree_dirty'],
        ]);

        // The first successful run establishes the baseline; a later run with an unchanged HEAD and
        // unchanged deterministic evidence never needs the LLM to re-derive the same advisory.
        if ($baselineRun !== null
            && $baselineRun->evaluated_head_sha !== null
            && $baselineRun->evaluated_head_sha === $snapshotResult['head_sha']
            && $baselineRun->snapshot_hash === $snapshotResult['snapshot_hash']) {
            $run->update(['status' => ProjectReconciliationStatus::SkippedNoChange, 'finished_at' => now()]);

            $this->audit->record('reconciliation.skipped_no_change', [
                'reconciliation_run_id' => $run->id,
                'evaluated_head_sha' => $snapshotResult['head_sha'],
            ], $project);

            return;
        }

        $this->audit->record('reconciliation.started', ['reconciliation_run_id' => $run->id], $project);

        try {
            [$agent, $harness] = $this->resolveAgent($project, AgentRole::ProjectManager);
        } catch (LogicException $exception) {
            $this->fail($run, $exception->getMessage());

            return;
        }

        $taskContext = $this->taskContext($snapshotResult['snapshot']);
        $assembled = $agent === null ? null : $this->contextAssembler->assemble($agent, AgentRole::ProjectManager, $taskContext);
        $prompt = $this->prompt($assembled?->toArray() ?? $taskContext);

        $agentRun = $this->runs->start($project, AgentRole::ProjectManager, $prompt, agent: $agent, context: $assembled);

        $sandboxPath = null;

        try {
            $this->databaseProtection->guard($project);

            [$sandboxProject, $sandboxPath] = $this->isolatedExecutionProject($project);

            $execution = $harness !== null && $agent !== null
                ? $harness->execute($sandboxProject, $agent, $prompt)->toArray()
                : $this->runner->run($sandboxProject, $prompt);
        } catch (Throwable $throwable) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
        } finally {
            if (is_string($sandboxPath) && $sandboxPath !== '') {
                $this->files->deleteDirectory($sandboxPath);
            }
        }

        $agentRun = $this->runs->complete($agentRun, $execution);

        if ($execution['exit_code'] !== 0) {
            $reason = $execution['error_output'] !== '' ? $execution['error_output'] : 'Project Manager reconciliation execution failed.';
            $this->fail($run, $reason);

            return;
        }

        $structured = $this->parser->parseAgentMessage($execution['output']);

        if ($structured === null) {
            $this->fail($run, 'Project Manager returned malformed structured reconciliation output.');

            return;
        }

        try {
            $this->results->handle($run, $agentRun, $structured);
        } catch (Throwable $throwable) {
            $this->fail($run, $throwable->getMessage());
        }
    }

    private function claim(ProjectReconciliationRun $run): ?ProjectReconciliationRun
    {
        return DB::transaction(function () use ($run): ?ProjectReconciliationRun {
            $locked = ProjectReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            $status = $locked->getRawOriginal('status');

            // A run interrupted mid-execution (e.g. a crashed worker) stays claimable so a job
            // retry resumes it instead of leaving it stranded in Running indefinitely.
            if (! in_array($status, [ProjectReconciliationStatus::Queued->value, ProjectReconciliationStatus::Running->value], true)) {
                return null;
            }

            $locked->update(['status' => ProjectReconciliationStatus::Running, 'started_at' => $locked->started_at ?? now()]);

            return $locked->refresh()->load('project');
        }, attempts: 3);
    }

    /** @return array{0: ?Agent, 1: ?AgentHarness} */
    private function resolveAgent(Project $project, AgentRole $role): array
    {
        try {
            $agent = $this->agents->forRole($project, $role);
        } catch (AgentNotBoundToRole) {
            return [null, null];
        }

        return [$agent, $this->harnesses->resolve($agent)];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function taskContext(array $snapshot): array
    {
        return [
            'objective' => 'Produce a read-only advisory reconciliation summary of current project status, functionality, and documentation drift from the supplied deterministic evidence only.',
            'acceptance_criteria' => [
                'Use only the supplied AIOS context. Do not inspect the filesystem.',
                'Do not edit any repository file, Obsidian note, Skill, rule, Task, Ticket, workflow state, Agent configuration, or Git state.',
                'Never attribute uncommitted or working-tree-only content to completed committed functionality.',
                'Return exactly one JSON object matching the required schema and no prose.',
            ],
            'project_snapshot' => $snapshot,
        ];
    }

    /** @param array<string, mixed> $context */
    private function prompt(array $context): string
    {
        $schema = [
            'project_status' => 'concise non-empty string',
            'functionality_summary' => 'concise non-empty string',
            'functionality_delta' => ['unchanged|added|changed|removed|uncertain' => [['summary' => 'string', 'evidence_paths' => ['string'], 'evidence_shas' => ['string']]]],
            'documentation_findings' => [['target_source' => 'string', 'target_category' => 'documentation|rule|regression_test', 'evidence_paths' => ['string'], 'evidence_shas' => ['string'], 'observed_implementation' => 'string', 'documented_claim' => 'string', 'reason_for_drift' => 'string', 'proposed_alignment' => 'string', 'confidence' => '0..1', 'deterministic' => 'boolean', 'requires_knowledge_architect_analysis' => 'boolean']],
            'resolved_drift' => ['string, a previously observed drift identifier resolved by current evidence'],
            'obsidian_findings' => ['string, one item per relevant Obsidian project-note finding'],
            'risks' => ['string, one item per identified risk'],
            'recommended_actions' => ['string, one item per recommended next action'],
        ];

        return implode("\n", [
            'Project Manager reconciliation advisory execution.',
            'Use only the supplied AIOS context. Do not inspect the filesystem.',
            'Do not write or mutate any repository file, Obsidian note, Skill, rule, Task, Ticket, workflow state, Agent configuration, or Git state.',
            'Do not return chain-of-thought or hidden reasoning.',
            'Return exactly one JSON object and no prose.',
            'Required schema: '.json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            '',
            'AIOS assembled context:',
            json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Create a disposable empty workspace while preserving the persisted project ID for
     * attribution, so the harness reasons from the supplied context only and cannot mutate the
     * live managed repository.
     *
     * @return array{0: Project, 1: string}
     */
    private function isolatedExecutionProject(Project $project): array
    {
        $path = $this->paths->resolve('.aios-project-reconciliation/'.Str::uuid());
        $this->files->ensureDirectoryExists($path);

        $sandboxProject = clone $project;
        $sandboxProject->setAttribute('path', $path);

        return [$sandboxProject, $path];
    }

    private function fail(ProjectReconciliationRun $run, string $reason): void
    {
        $run->refresh();

        if (in_array($run->getRawOriginal('status'), [ProjectReconciliationStatus::Failed->value, ProjectReconciliationStatus::Completed->value], true)) {
            return;
        }

        $run->update(['status' => ProjectReconciliationStatus::Failed, 'failure_reason' => $reason, 'finished_at' => now()]);

        $this->audit->record('reconciliation.failed', [
            'reconciliation_run_id' => $run->id,
            'reason' => $reason,
        ], $run->project);
    }
}
