<?php

use App\Actions\RunProjectManager;
use App\Models\Project;
use App\Models\Roadmap;
use App\ProjectStatus;
use App\Services\TaskContextCapsuleFactory;
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

it('persists a vertical-slice contract and treats omitted PM dependencies as independent', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));

    $project = Project::create([
        'name' => 'Vertical Slice Example',
        'path' => '/tmp/example-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/roadmap.md',
        'status' => 'uploaded',
        'content' => 'Let members invite a teammate.',
    ]);
    $plan = [
        'project_knowledge' => ['constraints' => []],
        'phases' => [[
            'title' => 'Team invitations',
            'objective' => 'Ship invite behavior.',
            'tasks' => [[
                'title' => 'Invite a team member',
                'objective' => 'An authorized member can invite a teammate and observe the pending invitation.',
                'work_type' => 'feature',
                'complexity' => 'medium',
                'slice_classification' => 'vertical_feature',
                'observable_behavior' => 'An authorized member submits an invite and sees the pending invitation state.',
                'verification_boundary' => 'Feature test covers authorization, persistence, and the invitation response.',
                'acceptance_contract' => 'A complete invitation flow works end to end and is regression-tested.',
                'acceptance_criteria' => ['Authorized members can create an invitation.', 'Unauthorized members are rejected.'],
                'scope' => ['Invitation persistence, authorization, UI/API behavior, and tests.'],
                'constraints' => ['Reuse existing team authorization.'],
                'relevant_paths' => ['app', 'resources/js', 'tests/Feature'],
                'verification_commands' => ['php artisan test --compact tests/Feature/InvitationTest.php'],
                'implementation_prompt' => 'Implement the full invitation behavior as one testable vertical slice.',
            ], [
                'title' => 'Harden invitation rate limits',
                'objective' => 'Prevent abusive invitation attempts without changing product behavior.',
                'work_type' => 'other',
                'complexity' => 'low',
                'slice_classification' => 'hardening',
                'observable_behavior' => 'Excessive invitation attempts are rejected by the existing rate-limit boundary.',
                'verification_boundary' => 'Feature test demonstrates the configured throttling behavior.',
                'acceptance_contract' => 'The bounded security hardening is covered by an automated regression test.',
                'acceptance_criteria' => ['Rate-limited requests are rejected.'],
                'implementation_prompt' => 'Implement only the bounded rate-limit hardening.',
            ]],
        ]],
        'remaining_work' => false,
    ];
    $output = json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($plan, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR);
    Process::fake(['*' => Process::result(output: $output)]);

    app(RunProjectManager::class)->handle($roadmap);

    $tasks = $project->tasks()->orderBy('position')->get();
    $task = $tasks->firstOrFail();

    expect($task->dependencies()->count())->toBe(0)
        ->and($task->context_capsule)->toMatchArray([
            'slice_classification' => 'vertical_feature',
            'observable_behavior' => 'An authorized member submits an invite and sees the pending invitation state.',
            'verification_boundary' => 'Feature test covers authorization, persistence, and the invitation response.',
            'acceptance_contract' => 'A complete invitation flow works end to end and is regression-tested.',
        ])
        ->and(app(TaskContextCapsuleFactory::class)->make($task)['planning'])->toMatchArray([
            'slice_classification' => 'vertical_feature',
            'acceptance_contract' => 'A complete invitation flow works end to end and is regression-tested.',
        ])
        ->and($tasks[1]->context_capsule['slice_classification'])->toBe('hardening')
        ->and($tasks[1]->dependencies()->count())->toBe(0);
});
