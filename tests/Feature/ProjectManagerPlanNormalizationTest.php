<?php

use App\Actions\RunProjectManager;
use App\Models\Project;
use App\Models\Roadmap;
use App\ProjectStatus;
use Illuminate\Support\Facades\Process;

it('normalizes scalar Project Manager knowledge constraints before strict plan validation', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create([
        'name' => 'Example',
        'path' => '/tmp/example-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/roadmap.md',
        'status' => 'uploaded',
        'content' => 'Build the application.',
    ]);
    $plan = [
        'project_knowledge' => [
            'overview' => 'Keep the implementation aligned with the current architecture.',
            'architecture_decisions' => [],
            'constraints' => 'Preserve tenant isolation.',
            'handoff' => 'Start with the first eligible task.',
        ],
        'phases' => [[
            'title' => 'Foundation',
            'objective' => 'Build the first vertical slice.',
            'tasks' => [[
                'title' => 'First task',
                'objective' => 'Lay the foundation.',
                'acceptance_criteria' => ['It works.'],
                'implementation_prompt' => 'Implement it.',
                'depends_on' => [],
            ]],
        ]],
        'remaining_work' => false,
    ];
    $output = json_encode([
        'type' => 'item.completed',
        'item' => [
            'type' => 'agent_message',
            'text' => json_encode($plan, JSON_THROW_ON_ERROR),
        ],
    ], JSON_THROW_ON_ERROR);
    Process::fake(['*' => Process::result(output: $output)]);

    app(RunProjectManager::class)->handle($roadmap);

    expect($roadmap->refresh()->status)->toBe('processed')
        ->and($project->tasks()->count())->toBe(1)
        ->and($project->auditEvents()->where('event_type', 'roadmap.processing_failed')->doesntExist())->toBeTrue();
});
