<?php

use App\Actions\ApplyRoadmapPlan;
use App\Actions\RunProjectManager;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapAttempt;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\ObsidianProjectNotes;
use App\Services\StaleWorkerRecovery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

use function Pest\Laravel\mock;

test('an authenticated user can register an existing Git project inside the workspace', function () {
    config()->set('aios.workspace_root', base_path('tests-workspace'));
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $path = 'existing-project-'.fake()->uuid();
    File::ensureDirectoryExists(base_path('tests-workspace/'.$path));
    Process::fake(['*' => Process::result(output: 'true')]);

    $this->actingAs(User::factory()->create())
        ->post(route('projects.store'), ['name' => 'Existing Project', 'path' => $path, 'mode' => 'existing'])
        ->assertRedirect();

    $project = Project::query()->sole();

    expect($project->path)->toBe(base_path('tests-workspace/'.$path))
        ->and($project->auditEvents()->where('event_type', 'project.registered')->exists())->toBeTrue()
        ->and($project->workers)->toHaveCount(3)
        ->and(File::get($vault.'/Projects/existing-project/Project Overview.md'))->toContain('Existing Project');
});

test('an existing project must be a Git repository', function () {
    config()->set('aios.workspace_root', base_path('tests-workspace'));
    $path = 'not-a-git-project-'.fake()->uuid();
    File::ensureDirectoryExists(base_path('tests-workspace/'.$path));
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->actingAs(User::factory()->create())
        ->from(route('projects.index'))
        ->post(route('projects.store'), ['name' => 'Existing Project', 'path' => $path, 'mode' => 'existing'])
        ->assertRedirect(route('projects.index'))
        ->assertSessionHasErrors('path');
});

test('project registration captures the current Git state and commit', function () {
    config()->set('aios.workspace_root', base_path('tests-workspace'));
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $path = 'tracked-project-'.fake()->uuid();
    File::ensureDirectoryExists(base_path('tests-workspace/'.$path));
    Process::fake(['*' => Process::sequence([
        Process::result(output: 'true'),
        Process::result(output: ' M app/Example.php'),
        Process::result(output: 'abc123'),
    ])]);

    $this->actingAs(User::factory()->create())
        ->post(route('projects.store'), ['name' => 'Tracked Project', 'path' => $path, 'mode' => 'existing'])
        ->assertRedirect();

    $project = Project::query()->sole();

    expect($project->git_status)->toBe('dirty')
        ->and($project->git_head_sha)->toBe('abc123');
});

test('an uploaded roadmap is recorded as project knowledge in Obsidian', function () {
    Storage::fake('local');
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);

    $this->actingAs(User::factory()->create())
        ->post(route('projects.roadmaps.store', $project), ['roadmap' => UploadedFile::fake()->createWithContent('roadmap.md', '# Inventory roadmap\n\nBuild the stock workflow.')])
        ->assertRedirect(route('projects.show', $project));

    expect(Roadmap::query()->sole()->content)->toContain('Build the stock workflow.')
        ->and(File::get($vault.'/Projects/example/Roadmaps/Latest Upload.md'))->toContain('# Inventory roadmap')
        ->and(File::get($vault.'/Projects/example/STATE.md'))->toContain('roadmap.md: uploaded');

    app(ObsidianProjectNotes::class)->writeRoadmapPlan($project, ['phases' => [['title' => 'Foundation', 'tasks' => [['title' => 'Bootstrap application']]]]]);

    expect(File::get($vault.'/Projects/example/Roadmaps/Implementation Plan.md'))->toContain('Bootstrap application');

    app(ObsidianProjectNotes::class)->writeProjectManagerKnowledge($project, [
        'overview' => 'Inventory work is delivered in verified phases.',
        'architecture_decisions' => [['title' => 'Use the existing stack', 'rationale' => 'It matches the repository conventions.']],
        'constraints' => ['Preserve tenant isolation.'],
        'handoff' => 'Start with the foundation phase.',
    ], [[
        'title' => 'Foundation',
        'objective' => 'Build the first vertical slice.',
        'tasks' => [['title' => 'Bootstrap application', 'objective' => 'Create the application skeleton.']],
    ]]);

    expect(File::get($vault.'/Projects/example/Roadmaps/Project Manager Knowledge.md'))->toContain('verified phases')
        ->and(File::get($vault.'/Projects/example/Decisions/Project Manager Decisions.md'))->toContain('Use the existing stack')
        ->and(File::get($vault.'/Projects/example/Handoffs/Project Manager Handoff.md'))->toContain('foundation phase')
        ->and(File::get($vault.'/Projects/example/Phases/01 - foundation.md'))->toContain('Bootstrap application');
});

test('roadmap context includes targeted project knowledge instead of unrelated notes', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $directory = $vault.'/Projects/example';
    File::ensureDirectoryExists($directory.'/Roadmaps');
    File::ensureDirectoryExists($directory.'/Tasks');
    File::put($directory.'/STATE.md', 'STATE CONTEXT');
    File::put($directory.'/Roadmaps/Project Manager Knowledge.md', 'ROADMAP CONSTRAINTS');
    File::put($directory.'/Tasks/TASK-999 - unrelated.md', 'UNRELATED TASK CONTEXT');

    $knowledge = app(ObsidianProjectNotes::class)->roadmapKnowledge($project);

    expect($knowledge)->toBe([
        'STATE.md' => 'STATE CONTEXT',
        'Roadmaps/Project Manager Knowledge.md' => 'ROADMAP CONSTRAINTS',
    ]);
});

test('an invalid Project Manager plan leaves the roadmap retryable instead of stuck processing', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'uploaded', 'content' => 'Build the application.']);
    $plan = ['phases' => [['title' => 'Foundation', 'objective' => 'Build it.', 'tasks' => []]]];
    $output = json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($plan, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR);
    Process::fake(['*' => Process::result(output: $output)]);

    app(RunProjectManager::class)->handle($roadmap);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($project->auditEvents()->where('event_type', 'roadmap.processing_started')->exists())->toBeTrue()
        ->and($project->auditEvents()->where('event_type', 'roadmap.processing_failed')->exists())->toBeTrue();
});

test('a Project Manager execution exception leaves durable failed evidence', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'uploaded', 'content' => 'Build the application.']);
    mock(CodexCliRunner::class)->shouldReceive('run')->once()->andThrow(new RuntimeException('The process ended unexpectedly.'));

    app(RunProjectManager::class)->handle($roadmap);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($project->runs()->value('exit_code'))->toBe(-1)
        ->and($project->auditEvents()->where('event_type', 'roadmap.processing_failed')->exists())->toBeTrue();
});

test('a Project Manager only runs after atomically claiming an uploaded roadmap', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'processing', 'content' => 'Build the application.']);
    mock(CodexCliRunner::class)->shouldNotReceive('run');

    $result = app(RunProjectManager::class)->handle($roadmap);

    expect($result)->toBe(['exit_code' => 0, 'output' => '', 'error_output' => ''])
        ->and($project->runs()->doesntExist())->toBeTrue();
});

test('a crash after a roadmap claim is recovered without losing the claimed attempt', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'processing', 'content' => 'Build the application.']);
    $attempt = RoadmapAttempt::create(['roadmap_id' => $roadmap->id, 'number' => 1, 'status' => 'claimed', 'claimed_at' => now()->subMinutes(5)]);

    app(StaleWorkerRecovery::class)->recover($project, 60);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($attempt->refresh()->status)->toBe('interrupted')
        ->and($project->auditEvents()->where('event_type', 'roadmap.processing_interrupted')->exists())->toBeTrue()
        ->and($project->auditEvents()->where('event_type', 'roadmap.retry_scheduled')->exists())->toBeTrue();
});

test('a stale processing roadmap preserves its interrupted run and retries in a fresh Project Manager context', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    foreach (AgentRole::cases() as $role) {
        AgentWorker::create(['project_id' => $project->id, 'role' => $role, 'status' => $role === AgentRole::ProjectManager ? 'working' : 'idle', 'last_heartbeat_at' => $role === AgentRole::ProjectManager ? now()->subMinutes(5) : now()]);
    }
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'processing', 'content' => 'Build the application.']);
    $previousRun = AgentRun::create(['project_id' => $project->id, 'role' => AgentRole::ProjectManager, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'previous'), 'started_at' => now()->subMinutes(5)]);
    $previousAttempt = RoadmapAttempt::create(['roadmap_id' => $roadmap->id, 'agent_run_id' => $previousRun->id, 'number' => 1, 'status' => 'running', 'claimed_at' => now()->subMinutes(5)]);
    $plan = validRoadmapPlan();
    $output = json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($plan, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR);
    Process::fake(['*' => Process::result(output: $output)]);

    expect(app(StaleWorkerRecovery::class)->recover($project, 60))->toBe(2)
        ->and($roadmap->refresh()->status)->toBe('failed');

    $this->artisan('aios:work --once')->assertExitCode(0);

    $attempts = $roadmap->attempts()->orderBy('number')->get();

    expect($roadmap->refresh()->status)->toBe('processed')
        ->and($previousAttempt->refresh()->status)->toBe('interrupted')
        ->and($previousRun->refresh()->status)->toBe(AgentRunStatus::Interrupted)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts->last()->status)->toBe('persisted')
        ->and($attempts->last()->agent_run_id)->not->toBe($previousRun->id);
});

test('a structured roadmap persistence failure rolls back all roadmap work and leaves a retryable attempt', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'uploaded', 'content' => 'Build the application.']);
    $plan = validRoadmapPlan();
    $output = json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($plan, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR);
    Process::fake(['*' => Process::result(output: $output)]);
    mock(ApplyRoadmapPlan::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Database write failed.'));

    app(RunProjectManager::class)->handle($roadmap);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($roadmap->attempts()->sole()->status)->toBe('failed')
        ->and($project->phases()->doesntExist())->toBeTrue()
        ->and($project->tasks()->doesntExist())->toBeTrue()
        ->and($project->auditEvents()->where('event_type', 'roadmap.processing_failed')->value('payload'))->toMatchArray(['reason' => 'persistence_failed']);
});

test('a failure during structured roadmap application rolls back created phases and tasks', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'processing', 'content' => 'Build the application.']);
    $attempt = RoadmapAttempt::create(['roadmap_id' => $roadmap->id, 'number' => 1, 'status' => 'running', 'claimed_at' => now()]);
    $plan = validRoadmapPlan();
    mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('The audit store failed.'));

    expect(fn () => app(ApplyRoadmapPlan::class)->handle($project, $plan['phases'], $roadmap, $attempt, $plan))
        ->toThrow(RuntimeException::class, 'The audit store failed.');

    expect($project->phases()->doesntExist())->toBeTrue()
        ->and($project->tasks()->doesntExist())->toBeTrue()
        ->and($roadmap->refresh()->status)->toBe('processing')
        ->and($attempt->refresh()->status)->toBe('running');
});

test('a successful roadmap retry persists phases and tasks exactly once', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'failed', 'content' => 'Build the application.']);
    RoadmapAttempt::create(['roadmap_id' => $roadmap->id, 'number' => 1, 'status' => 'failed', 'claimed_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
    $plan = validRoadmapPlan();
    $output = json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($plan, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR);
    Process::fake(['*' => Process::result(output: $output)]);

    app(RunProjectManager::class)->handle($roadmap);
    app(RunProjectManager::class)->handle($roadmap->refresh());

    expect($roadmap->refresh()->status)->toBe('processed')
        ->and($project->phases()->count())->toBe(1)
        ->and($project->tasks()->count())->toBe(2)
        ->and($roadmap->attempts()->count())->toBe(2)
        ->and($project->auditEvents()->where('event_type', 'roadmap.persistence_completed')->count())->toBe(1)
        ->and($project->auditEvents()->where('event_type', 'roadmap.processed')->count())->toBe(1);
});

/** @return array<string, mixed> */
function validRoadmapPlan(): array
{
    return ['phases' => [[
        'title' => 'Foundation',
        'objective' => 'Build it.',
        'tasks' => [
            ['title' => 'First task', 'objective' => 'Lay the foundation.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => []],
            ['title' => 'Second task', 'objective' => 'Finish the workflow.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => [1]],
        ],
    ]]];
}

test('a Project Manager plan preserves explicit task dependencies', function () {
    config()->set('aios.obsidian_vault_path', storage_path('framework/testing/obsidian-'.fake()->uuid()));
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'uploaded', 'content' => 'Build the application.']);
    $plan = ['phases' => [[
        'title' => 'Foundation',
        'objective' => 'Build it.',
        'tasks' => [
            ['title' => 'First task', 'objective' => 'Lay the foundation.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => []],
            ['title' => 'Second task', 'objective' => 'Add a separate component.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => [1]],
            ['title' => 'Third task', 'objective' => 'Combine the components.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => [1, 2]],
        ],
    ]]];
    $output = json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($plan, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR);
    Process::fake(['*' => Process::result(output: $output)]);

    app(RunProjectManager::class)->handle($roadmap);

    $tasks = $project->tasks()->orderBy('position')->get();

    expect($roadmap->refresh()->status)->toBe('processed')
        ->and($tasks[1]->dependencies()->pluck('position')->all())->toBe([1])
        ->and($tasks[2]->dependencies()->pluck('position')->all())->toBe([1, 2]);
});

test('a Project Manager plan rejects forward task dependencies', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create(['project_id' => $project->id, 'original_filename' => 'roadmap.md', 'storage_path' => 'roadmaps/roadmap.md', 'status' => 'uploaded', 'content' => 'Build the application.']);
    $plan = ['phases' => [['title' => 'Foundation', 'objective' => 'Build it.', 'tasks' => [['title' => 'First task', 'objective' => 'Lay the foundation.', 'acceptance_criteria' => ['It works.'], 'implementation_prompt' => 'Implement it.', 'depends_on' => [1]]]]]];
    $output = json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($plan, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR);
    Process::fake(['*' => Process::result(output: $output)]);

    app(RunProjectManager::class)->handle($roadmap);

    expect($roadmap->refresh()->status)->toBe('failed')
        ->and($project->tasks()->doesntExist())->toBeTrue();
});
