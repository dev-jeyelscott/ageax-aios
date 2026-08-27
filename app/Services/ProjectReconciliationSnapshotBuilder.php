<?php

namespace App\Services;

use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Process;
use Throwable;

class ProjectReconciliationSnapshotBuilder
{
    private const int MaxCommittedFiles = 200;

    private const int MaxCommittedCommits = 200;

    private const int MaxManifestEntries = 100;

    private const int MaxKnowledgeGapItems = 50;

    public function __construct(
        private ProjectGitState $git,
        private ProjectRuntimeCapabilityDetector $runtime,
        private KnowledgeSourceManifestSynchronizer $manifests,
        private KnowledgeGapDetector $gaps,
        private ObsidianProjectNotes $notes,
        private WorkspacePathResolver $paths,
    ) {}

    /**
     * Build the deterministic, bounded reconciliation snapshot and its stable hash. Only
     * committed Git history contributes to functionality evidence; the current working tree's
     * dirtiness is reported separately so a dirty repository is never misattributed as completed
     * committed behavior.
     *
     * @return array{
     *     snapshot: array<string, mixed>,
     *     snapshot_hash: string,
     *     head_sha: ?string,
     *     working_tree_dirty: bool
     * }
     */
    public function build(Project $project, ?string $baselineSha): array
    {
        $projectPath = $this->paths->assertProjectPath($project->path);
        $state = $this->git->inspect($projectPath);

        $headSha = $state['head_sha'];
        $workingTreeDirty = ! $state['inspectable'] || ! $state['clean'];

        $this->manifests->sync($project);

        $roadmapScannedAt = $project->getAttribute('roadmap_scanned_at');

        $snapshot = [
            'project_status' => (string) $project->getRawOriginal('status'),
            // baseline_sha is deliberately excluded from the hashed snapshot: it is a
            // diff parameter (already persisted separately on the run row), not project state,
            // and it legitimately changes from null to a real SHA between the first run and the
            // second even when nothing else about the project changed.
            'git' => [
                'inspectable' => $state['inspectable'],
                'clean' => $state['clean'],
                'head_sha' => $headSha,
            ],
            'committed_changes_since_baseline' => $this->committedEvidenceSince($projectPath, $baselineSha, $headSha),
            'task_counts_by_status' => $project->tasks()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all(),
            'phase_count' => $project->phases()->count(),
            'roadmap_scanned_at' => $roadmapScannedAt instanceof CarbonInterface ? $roadmapScannedAt->toIso8601String() : null,
            'runtime_capabilities' => $this->runtime->detect($project),
            'knowledge_source_manifest' => $this->manifestSummary($project),
            'knowledge_gaps' => array_slice($this->gaps->detect($project), 0, self::MaxKnowledgeGapItems),
            'obsidian_project_knowledge' => $this->notes->roadmapRetrieval($project)['notes'],
        ];

        return [
            'snapshot' => $snapshot,
            'snapshot_hash' => $this->stableHash($snapshot),
            'head_sha' => $headSha,
            'working_tree_dirty' => $workingTreeDirty,
        ];
    }

    /**
     * @return array{changed_files: list<string>, commits: list<array{sha: string, subject: string}>}
     */
    private function committedEvidenceSince(string $projectPath, ?string $baselineSha, ?string $headSha): array
    {
        if ($baselineSha === null || $headSha === null || $baselineSha === $headSha) {
            return ['changed_files' => [], 'commits' => []];
        }

        $range = $baselineSha.'..'.$headSha;

        $filesResult = $this->process($projectPath, ['git', 'diff', '--name-only', '--no-renames', '-z', $range, '--']);
        $logResult = $this->process($projectPath, ['git', 'log', '--pretty=format:%H%x1f%s', $range]);

        $files = [];

        if ($filesResult['successful']) {
            $files = array_values(array_unique(array_filter(
                explode("\0", $filesResult['output']),
                fn (string $file): bool => $file !== '',
            )));
            sort($files, SORT_STRING);
            $files = array_slice($files, 0, self::MaxCommittedFiles);
        }

        $commits = [];

        if ($logResult['successful'] && trim($logResult['output']) !== '') {
            foreach (array_slice(explode("\n", trim($logResult['output'])), 0, self::MaxCommittedCommits) as $line) {
                [$sha, $subject] = array_pad(explode("\x1f", $line, 2), 2, '');
                $commits[] = ['sha' => $sha, 'subject' => $subject];
            }
        }

        return ['changed_files' => $files, 'commits' => $commits];
    }

    /**
     * @return list<array{source_reference: string, content_hash: string}>
     */
    private function manifestSummary(Project $project): array
    {
        return array_values(KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->whereNull('superseded_at')
            ->orderBy('source_reference')
            ->limit(self::MaxManifestEntries)
            ->get(['source_reference', 'content_hash'])
            ->map(fn (KnowledgeSourceManifest $manifest): array => [
                'source_reference' => (string) $manifest->source_reference,
                'content_hash' => (string) $manifest->content_hash,
            ])
            ->all());
    }

    /**
     * @param  array<int, string>  $command
     * @return array{successful: bool, output: string}
     */
    private function process(string $projectPath, array $command): array
    {
        try {
            $result = Process::path($projectPath)->run($command);

            return ['successful' => $result->successful(), 'output' => $result->output()];
        } catch (Throwable) {
            return ['successful' => false, 'output' => ''];
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function stableHash(array $snapshot): string
    {
        return hash('sha256', json_encode(
            $this->sortRecursively($snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $value = array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }
}
