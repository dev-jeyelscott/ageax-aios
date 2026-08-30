<?php

use App\Actions\ApproveFeatureGoal;
use App\Actions\ClaimTask;
use App\Actions\StoreFeatureSpec;
use App\AgentRole;
use App\Models\Agent;
use App\Models\FeatureSpec;
use App\Models\GoalRun;
use App\Models\GoalRunVersion;
use App\Models\GoalSession;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\Services\GoalSessionExecutionSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('feature intake persists bounded text content and rejects duplicate hashes', function () {
    Storage::fake('local');
    $project = featureGoalProject();
    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent('feature.md', "# Add status endpoint\n\nInclude tests.");

    $featureSpec = app(StoreFeatureSpec::class)->handle($project, $file, $user);

    expect($featureSpec->content_hash)->toHaveLength(64)
        ->and($featureSpec->status)->toBe('uploaded')
        ->and(Storage::disk('local')->exists($featureSpec->storage_path))->toBeTrue();

    expect(fn () => app(StoreFeatureSpec::class)->handle($project, UploadedFile::fake()->createWithContent('copy.md', "# Add status endpoint\n\nInclude tests."), $user))
        ->toThrow(ValidationException::class);
});

test('approval versions an operator-edited canonical goal', function () {
    $project = featureGoalProject();
    $featureSpec = FeatureSpec::factory()->for($project)->create();
    $task = Task::create(['project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Feature task', 'objective' => 'Implement the feature.', 'acceptance_criteria' => ['Works'], 'scope' => [], 'constraints' => [], 'relevant_paths' => [], 'verification_commands' => [], 'implementation_prompt' => 'Implement it.', 'context_capsule' => [], 'status' => 'queued']);
    $goalRun = GoalRun::factory()->for($project)->for($featureSpec)->for($task)->create(['status' => 'awaiting_approval', 'goal_text' => '/goal initial']);

    $initialVersion = new GoalRunVersion;
    $initialVersion->goal_run_id = $goalRun->id;
    $initialVersion->version = 1;
    $initialVersion->goal_text = '/goal initial';
    $initialVersion->source = 'project_manager';
    $initialVersion->save();
    app(ApproveFeatureGoal::class)->handle($goalRun, '/goal edited');

    expect($goalRun->refresh()->status)->toBe('approved')
        ->and($goalRun->goal_text)->toBe('/goal edited')
        ->and($goalRun->version)->toBe(2)
        ->and($goalRun->approved_at)->not->toBeNull()
        ->and($goalRun->versions()->pluck('goal_text')->all())->toBe(['/goal initial', '/goal edited']);
});

test('approval-required goals cannot enter the Coder lane before approval', function () {
    $project = featureGoalProject();
    $project->update(['status' => ProjectStatus::Running]);
    $featureSpec = FeatureSpec::factory()->for($project)->create();
    $task = Task::create(['project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Feature task', 'objective' => 'Implement the feature.', 'acceptance_criteria' => ['Works'], 'scope' => [], 'constraints' => [], 'relevant_paths' => [], 'verification_commands' => [], 'implementation_prompt' => 'Implement it.', 'context_capsule' => [], 'status' => 'queued']);
    $goalRun = GoalRun::factory()->for($project)->for($featureSpec)->for($task)->create(['status' => 'awaiting_approval']);

    expect(app(ClaimTask::class)->handle($project, AgentRole::Coder))->toBeNull();

    app(ApproveFeatureGoal::class)->handle($goalRun);

    expect(app(ClaimTask::class)->handle($project, AgentRole::Coder)?->id)->toBe($task->id);
});

test('goal session execution settings resume only a durable same-role provider session', function () {
    $project = featureGoalProject();
    $featureSpec = FeatureSpec::factory()->for($project)->create();
    $task = Task::create(['project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Feature task', 'objective' => 'Implement the feature.', 'acceptance_criteria' => ['Works'], 'scope' => [], 'constraints' => [], 'relevant_paths' => [], 'verification_commands' => [], 'implementation_prompt' => 'Implement it.', 'context_capsule' => [], 'status' => 'queued']);
    $goalRun = GoalRun::factory()->for($project)->for($featureSpec)->for($task)->create();
    $agent = Agent::factory()->for($project)->create(['role' => AgentRole::BackendEngineer]);
    GoalSession::create(['goal_run_id' => $goalRun->id, 'agent_id' => $agent->id, 'role' => AgentRole::BackendEngineer, 'harness' => 'codex', 'provider_session_id' => 'provider-session-id', 'status' => 'active']);

    expect(app(GoalSessionExecutionSettings::class)->for($goalRun, AgentRole::BackendEngineer))
        ->toBe(['provider_session_id' => 'provider-session-id'])
        ->and(app(GoalSessionExecutionSettings::class)->for($goalRun, AgentRole::Reviewer))
        ->toBe([]);
});

test('governance scopes warm sessions to GoalRuns without weakening legacy execution contracts', function () {
    expect(file_get_contents(base_path('AGENTS.md')))
        ->toContain('except the Phase 14 Feature Goal path')
        ->toContain('Legacy Coder, Roadmap, and Ticket execution remain fresh-context paths')
        ->and(file_get_contents(base_path('.ai/rules/goals.md')))
        ->toContain('exactly one canonical Task')
        ->toContain('isolated by GoalRun and role')
        ->and(file_get_contents(base_path('.codex/agents/reviewer-goal.md')))
        ->toContain('Do not rely on Backend Engineer hidden session memory.');
});

test('goal UI exposes editable approval, isolated session states, and durable evidence', function () {
    $source = file_get_contents(resource_path('js/pages/projects/show.tsx'));

    expect($source)
        ->toContain('Canonical /goal (edits create an immutable new version)')
        ->toContain("['project_manager', 'backend_engineer', 'reviewer']")
        ->toContain('Validated commit:')
        ->not->toContain('provider_session_id');
});

test('failed feature planning requires an explicit retry instead of looping the PM worker', function () {
    $source = file_get_contents(app_path('Console/Commands/RunAiosWorkers.php'));

    expect($source)
        ->toContain("->where('status', 'uploaded')")
        ->not->toContain("->whereIn('status', ['uploaded', 'planning_failed'])");
});

function featureGoalProject(): Project
{
    return Project::create(['name' => 'Feature Goal', 'path' => sys_get_temp_dir().'/feature-goal-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
}
