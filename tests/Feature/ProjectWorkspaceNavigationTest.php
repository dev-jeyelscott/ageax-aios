<?php

use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;
use Inertia\Testing\AssertableInertia as Assert;

function projectWorkspaceNavigationProject(): Project
{
    return Project::create([
        'name' => 'Workspace Navigation',
        'path' => '/tmp/workspace-navigation-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

test('project workspace canonical urls remain directly addressable and refresh without redirects', function () {
    $user = User::factory()->create();
    $project = projectWorkspaceNavigationProject();

    $urls = [
        'overview' => route('projects.show', $project),
        'agents' => route('projects.show', [
            'project' => $project,
            'tab' => 'agents',
        ]),
        'skills' => route('projects.show', [
            'project' => $project,
            'tab' => 'skills',
        ]),
        'tasks' => route('projects.show', [
            'project' => $project,
            'tab' => 'tasks',
        ]),
        'activity' => route('projects.show', [
            'project' => $project,
            'tab' => 'activity',
        ]),
    ];

    foreach ($urls as $url) {
        $response = $this->actingAs($user)->get($url);

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.id', $project->id)
                ->etc());

        expect($response->headers->has('Location'))->toBeFalse();

        $refreshResponse = $this->actingAs($user)->get($url);

        $refreshResponse
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.id', $project->id)
                ->etc());

        expect($refreshResponse->headers->has('Location'))->toBeFalse();
    }
});

test('tickets and knowledge keep their existing project scoped routes and pages', function () {
    $user = User::factory()->create();
    $project = projectWorkspaceNavigationProject();

    $this->actingAs($user)
        ->get(route('projects.tickets.index', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tickets/index')
            ->where('project.id', $project->id)
            ->etc());

    $this->actingAs($user)
        ->get(route('projects.knowledge-improvements.index', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/knowledge-improvements/index')
            ->where('project.id', $project->id)
            ->etc());
});

test('project workspace navigation is url driven and polling cannot rewrite browser history', function () {
    $projectShow = file_get_contents(
        resource_path('js/pages/projects/show.tsx'),
    );
    $projectLayout = file_get_contents(
        resource_path('js/layouts/app/app-sidebar-layout.tsx'),
    );

    expect($projectShow)
        ->toContain('usePage')
        ->toContain('new URLSearchParams')
        ->toContain('const tab = resolveProjectTab(url)')
        ->toContain('usePoll(')
        ->toContain('preserveUrl: true')
        ->not->toContain("const [tab, setTab] = useState<Tab>('overview')")
        ->not->toContain('onClick={() => setTab(value)}')
        ->not->toContain('projectTabUrl(')
        ->not->toContain('router.visit(')
        ->not->toContain('router.get(');

    expect($projectLayout)
        ->toContain('aria-label="Project sections"')
        ->toContain("label: 'Overview'")
        ->toContain("label: 'Agents'")
        ->toContain("label: 'Skills'")
        ->toContain("label: 'Tasks'")
        ->toContain("label: 'Tickets'")
        ->toContain("label: 'Knowledge'")
        ->toContain("label: 'Recent Activity'")
        ->toContain('href={href}')
        ->toContain(
            "activeSection === value\n                                                    ? 'page'",
        )
        ->toContain(
            'currentPath.startsWith(`${projectPath}/tasks/`)',
        )
        ->toContain(
            'currentPath.startsWith(`${projectPath}/agent-runs/`)',
        )
        ->not->toContain('replace=')
        ->not->toContain('onClick=');
});

test('project specific tickets and knowledge are absent from the application sidebar', function () {
    $sidebar = file_get_contents(
        resource_path('js/components/app-sidebar.tsx'),
    );

    expect($sidebar)
        ->toContain("title: 'Dashboard'")
        ->toContain("title: 'Projects'")
        ->toContain("title: 'Ticket Operations'")
        ->toContain("title: 'Harness Scorecards'")
        ->toContain("title: 'Agents'")
        ->not->toContain("title: 'Tickets'")
        ->not->toContain("title: 'Knowledge'")
        ->not->toContain('ticketsIndex(project.id)')
        ->not->toContain('knowledgeImprovementsIndex(project.id)')
        ->not->toContain('usePage');
});
