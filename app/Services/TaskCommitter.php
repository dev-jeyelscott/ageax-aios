<?php

namespace App\Services;

use App\Models\Task;
use App\TaskCommitMessage;
use Illuminate\Support\Facades\Process;

class TaskCommitter
{
    public function __construct(private WorkspacePathResolver $paths, private ProjectGitState $git, private TaskCommitMessage $messages) {}

    /** @param array<int, string> $changedFiles */
    public function commit(Task $task, array $changedFiles, ?string $baseSha = null): ?string
    {
        $task->loadMissing('project');
        $projectPath = $this->paths->assertProjectPath($task->project->path);
        $changedFiles = $this->normalize($changedFiles);

        if ($changedFiles === []) {
            return null;
        }

        if ($baseSha !== null && (! $this->git->baseMatchesCurrentHead($projectPath, $baseSha)
            || ! $this->git->matchesExpectedChanges($projectPath, $baseSha, $changedFiles))) {
            return null;
        }

        $stagedBefore = $this->git->stagedFiles($projectPath);

        if ($stagedBefore === null || array_diff($stagedBefore, $changedFiles) !== []) {
            return null;
        }

        $add = Process::path($projectPath)->run(['git', '--literal-pathspecs', 'add', '--', ...$changedFiles]);

        if ($add->failed()) {
            return null;
        }

        $stagedAfter = $this->git->stagedFiles($projectPath);

        if ($stagedAfter === null || $stagedAfter !== $changedFiles) {
            return null;
        }

        if ($baseSha !== null && (! $this->git->baseMatchesCurrentHead($projectPath, $baseSha)
            || ! $this->git->matchesExpectedChanges($projectPath, $baseSha, $changedFiles))) {
            return null;
        }

        $commit = Process::path($projectPath)->run(['git', '--literal-pathspecs', 'commit', '--only', '-m', $this->messages->for($task), '--', ...$changedFiles]);

        if ($commit->failed()) {
            return null;
        }

        $state = $this->git->inspect($projectPath);
        $commitSha = $state['head_sha'];
        $task->project->update([
            'git_head_sha' => $commitSha,
            'git_status' => $state['inspectable'] ? ($state['clean'] ? 'clean' : 'dirty') : 'unknown',
        ]);

        return $commitSha;
    }

    /**
     * @param  array<int, string>  $files
     * @return array<int, string>
     */
    private function normalize(array $files): array
    {
        $files = array_values(array_unique(array_filter($files, fn (string $file): bool => $file !== '')));
        sort($files, SORT_STRING);

        return $files;
    }
}
