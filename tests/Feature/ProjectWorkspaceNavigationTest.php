<?php

use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('project workspace sections remain directly addressable through the project show route', function (string $tab) {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Workspace Navigation',
        'path' => '/tmp/workspace-navigation-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', [
            'project' => $project,
            'tab' => $tab,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.id', $project->id)
            ->etc());
})->with([
    'overview',
    'agents',
    'skills',
    'tasks',
    'activity',
]);

test('project workspace navigation is driven by the current inertia url', function () {
    $source = file_get_contents(resource_path('js/pages/projects/show.tsx'));

    expect($source)
        ->toContain('usePage')
        ->toContain('show as showProject')
        ->toContain('new URLSearchParams')
        ->toContain('const tab = resolveProjectTab(url)')
        ->toContain('href={projectTabUrl(project.id, value)}')
        ->toContain("tab === value ? 'page' : undefined")
        ->not->toContain("const [tab, setTab] = useState<Tab>('overview')")
        ->not->toContain('onClick={() => setTab(value)}');
});
