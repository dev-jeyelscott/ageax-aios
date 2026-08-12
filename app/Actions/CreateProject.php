<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\AuditLogger;
use App\Services\ObsidianProjectNotes;
use App\Services\WorkspacePathResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

class CreateProject
{
    public function __construct(private WorkspacePathResolver $paths, private AuditLogger $audit, private ObsidianProjectNotes $notes) {}

    public function handle(string $name, string $path, bool $existing = false): Project
    {
        $projectPath = $this->paths->resolve($path, $existing);

        if ($existing && ! is_dir($projectPath)) {
            throw ValidationException::withMessages(['path' => 'The existing project directory could not be found.']);
        }

        if (! $existing && file_exists($projectPath)) {
            throw ValidationException::withMessages(['path' => 'A project already exists at this workspace path.']);
        }

        if (Project::query()->where('path', $projectPath)->exists()) {
            throw ValidationException::withMessages(['path' => 'This project is already registered in AIOS.']);
        }

        if (! $existing) {
            mkdir($projectPath, 0755, true);
        }

        $git = Process::path($projectPath)->run($existing ? ['git', 'rev-parse', '--is-inside-work-tree'] : ['git', 'init']);

        if ($git->failed() || ($existing && trim($git->output()) !== 'true')) {
            if (! $existing) {
                rmdir($projectPath);
            }

            throw ValidationException::withMessages(['path' => $existing ? 'The existing project must be a Git repository.' : 'Git could not initialize this project directory.']);
        }

        $gitState = $this->gitState($projectPath);

        $project = DB::transaction(function () use ($name, $projectPath, $existing, $gitState): Project {
            $project = Project::create(['name' => $name, 'path' => $projectPath, 'status' => ProjectStatus::Paused, 'git_status' => $gitState['status'], 'git_head_sha' => $gitState['head_sha']]);

            foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
                AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => 'idle']);
            }

            $this->audit->record($existing ? 'project.registered' : 'project.created', ['path' => $projectPath], $project);

            return $project;
        }, attempts: 3);

        $this->notes->writeOverview($project);

        return $project;
    }

    /** @return array{status: 'clean'|'dirty'|'unknown', head_sha: ?string} */
    private function gitState(string $projectPath): array
    {
        $status = Process::path($projectPath)->run(['git', 'status', '--porcelain']);
        $head = Process::path($projectPath)->run(['git', 'rev-parse', 'HEAD']);

        return [
            'status' => $status->successful() ? (blank($status->output()) ? 'clean' : 'dirty') : 'unknown',
            'head_sha' => $head->successful() ? trim($head->output()) : null,
        ];
    }
}
