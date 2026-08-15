<?php

use App\Actions\RunProjectManager;
use App\Models\Project;
use App\Models\Roadmap;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\mock;

test('the Project Manager receives runtime capability context before planning', function () {
    $path = sys_get_temp_dir().'/aios-pm-runtime-'.fake()->uuid();
    File::ensureDirectoryExists($path);

    try {
        $project = Project::create(['name' => 'Runtime PM', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
        $roadmap = Roadmap::create([
            'project_id' => $project->id,
            'original_filename' => 'roadmap.md',
            'storage_path' => 'roadmaps/runtime.md',
            'status' => 'uploaded',
            'content' => 'Implement the runtime-aware task.',
        ]);

        mock(CodexCliRunner::class)
            ->shouldReceive('run')
            ->once()
            ->withArgs(function (Project $runProject, string $prompt): bool {
                expect($runProject->id)->toBeGreaterThan(0)
                    ->and($prompt)->toContain('runtime_capabilities')
                    ->and($prompt)->toContain('never declare a project dependency unavailable from host-only checks');

                return true;
            })
            ->andReturn(['exit_code' => 1, 'output' => '', 'error_output' => 'Expected test stop.']);

        app(RunProjectManager::class)->handle($roadmap);

        expect($project->auditEvents()->where('event_type', 'runtime.capabilities_detected')->exists())->toBeTrue();
    } finally {
        File::deleteDirectory($path);
    }
});
