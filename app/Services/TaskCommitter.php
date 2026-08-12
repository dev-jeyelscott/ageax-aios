<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Process;

class TaskCommitter
{
    public function __construct(private WorkspacePathResolver $paths) {}

    /** @param array<int, string> $changedFiles */
    public function commit(Task $task, array $changedFiles): ?string
    {
        $path = $this->paths->assertProjectPath($task->project->path);
        if ($changedFiles === []) {
            return null;
        }

        $add = Process::path($path)->run(['git', 'add', '--', ...$changedFiles]);
        if ($add->failed()) {
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
        $status = Process::path($path)->run(['git', 'status', '--porcelain']);
        $task->project->update([
            'git_head_sha' => $commitSha,
            'git_status' => blank(trim($status->output())) ? 'clean' : 'dirty',
        ]);

        return $commitSha;
    }
}
