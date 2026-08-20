<?php

use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the AI workflow has a dedicated canonical project route that survives refresh', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Dedicated Workflow',
        'path' => '/tmp/dedicated-workflow-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $url = route('projects.workflow', $project);

    foreach (range(1, 2) as $requestNumber) {
        $response = $this->actingAs($user)->get($url);

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.id', $project->id)
                ->etc());

        expect($response->headers->has('Location'))
            ->toBeFalse("workflow request {$requestNumber} redirected");
    }
});

test('project overview no longer renders the AI engineering workflow canvas', function () {
    $source = file_get_contents(
        resource_path('js/pages/projects/show.tsx'),
    );

    $overview = Str::between(
        $source,
        'function OverviewDashboard(',
        'function WorkflowDashboard(',
    );
    $workflow = Str::between(
        $source,
        'function WorkflowDashboard(',
        'function TasksPanel(',
    );

    expect($overview)
        ->toContain('data-project-overview-dashboard="true"')
        ->toContain('Operational overview')
        ->toContain('Roadmap progress')
        ->toContain('Current operation')
        ->toContain('Task flow')
        ->toContain('Repository & validation')
        ->toContain('Execution & token usage')
        ->toContain('Workflow agents')
        ->toContain('Recent project signals')
        ->not->toContain('<ClientAgentOffice');

    expect($workflow)
        ->toContain('data-project-ai-workflow="true"')
        ->toContain('ai-workflow-fullscreen')
        ->toContain('<ClientAgentOffice');
});

test('project navigation exposes AI workflow as a stable url driven section', function () {
    $layout = file_get_contents(
        resource_path('js/layouts/app/app-sidebar-layout.tsx'),
    );
    $show = file_get_contents(
        resource_path('js/pages/projects/show.tsx'),
    );

    expect($layout)
        ->toContain("| 'workflow'")
        ->toContain("label: 'AI Workflow'")
        ->toContain('projectWorkflowUrl(projectId)')
        ->toContain("return `${projectPath}/workflow`;")
        ->toContain("return 'workflow';");

    expect($show)
        ->toContain("{ value: 'workflow', label: 'AI Workflow' }")
        ->toContain("normalizedPath.endsWith('/workflow')")
        ->toContain("tab === 'workflow'")
        ->toContain('preserveUrl: true');
});

test('dedicated AI workflow keeps the authoritative graph and scopes fullscreen motion safely', function () {
    $office = file_get_contents(
        resource_path('js/components/agent-office.tsx'),
    );
    $styles = file_get_contents(
        resource_path('js/pages/projects/project-workflow.css'),
    );

    expect($office)
        ->toContain(
            "const preferredRoleOrder = ['project_manager', 'coder', 'reviewer'] as const;",
        )
        ->toContain('pmToCoderState')
        ->toContain('coderToReviewerState')
        ->toContain('worker.run?.latest_message')
        ->toContain('data-connector-state={state}')
        ->toContain('/action-gif/pm-idle.gif')
        ->toContain('/action-gif/coder-idle.gif')
        ->toContain('/action-gif/reviewer-idle.gif');

    expect($styles)
        ->toContain('.ai-workflow-fullscreen')
        ->toContain(
            '.ai-workflow-fullscreen .execution-command-body',
        )
        ->toContain('grid-template-rows: minmax(0, 1fr) auto;')
        ->toContain(
            '.ai-workflow-fullscreen .execution-operations-panel',
        )
        ->toContain('grid-template-columns:')
        ->toContain('.execution-operations-pair > :last-child')
        ->toContain('.overview-motion-card')
        ->toContain('workflow-active-card-glow')
        ->toContain('@media (max-width: 79.999rem)')
        ->toContain('@media (max-width: 47.999rem)')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});
