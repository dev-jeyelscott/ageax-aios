<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Process;

class TaskCommitter
{
    public function __construct(private WorkspacePathResolver $paths, private GitRepositoryInspector $git) {}

    /** @param array<int, string> $changedFiles */
    public function commit(Task $task, array $changedFiles, ?string $baseSha = null): ?string
    {
        $path = $this->paths->assertProjectPath($task->project->path);
        $expectedFiles = $this->normalizeFiles($changedFiles);
        if ($expectedFiles === []) {
            return null;
        }

        $beforeStaging = $this->git->inspect($path);
        $baseSha ??= $beforeStaging['head_sha'];
        if (! $beforeStaging['inspectable'] || $baseSha === null || $beforeStaging['head_sha'] !== $baseSha) {
            return null;
        }

        $unexpectedStagedFiles = array_values(array_diff($beforeStaging['index_files'], $expectedFiles));
        if ($unexpectedStagedFiles !== []) {
            return null;
        }

        $add = Process::path($path)->run(['git', 'add', '--', ...$expectedFiles]);
        if ($add->failed()) {
            return null;
        }

        $afterStaging = $this->git->inspect($path);
        if (! $afterStaging['inspectable']
            || $afterStaging['head_sha'] !== $baseSha
            || $afterStaging['index_files'] !== $expectedFiles) {
            return null;
        }

        $commit = Process::path($path)->run(['git', 'commit', '-m', "{$task->key}: {$task->title}"]);

        if ($commit->failed()) {
            return null;
        }

        $head = Process::path($path)->run(['git', 'rev-parse', 'HEAD']);

        if ($head->failed()) {
            return null;
        }

        $commitSha = trim($head->output());
        $status = $this->git->inspect($path);
        $task->project->update([
            'git_head_sha' => $commitSha,
            'git_status' => ! $status['inspectable'] ? 'unknown' : ($status['clean'] ? 'clean' : 'dirty'),
        ]);

        return $commitSha;
    }

    /** @param array<int, string> $files
     *  @return array<int, string>
     */
    private function normalizeFiles(array $files): array
    {
        return collect($files)
            ->filter(fn (string $file): bool => $file !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
