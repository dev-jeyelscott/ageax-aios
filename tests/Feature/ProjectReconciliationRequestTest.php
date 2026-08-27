<?php

use App\Actions\RequestProjectReconciliation;
use App\Jobs\ProcessProjectReconciliation;
use App\Models\Project;
use App\Models\ProjectReconciliationRun;
use App\Models\User;
use App\ProjectReconciliationStatus;
use App\ProjectReconciliationTrigger;
use App\ProjectStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

function reconciliationRequestProject(): Project
{
    return Project::create([
        'name' => 'Example',
        'path' => '/tmp/example-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

test('requesting a reconciliation persists a queued run and dispatches the processing job', function () {
    Queue::fake();

    $project = reconciliationRequestProject();
    $user = User::factory()->create();

    $run = app(RequestProjectReconciliation::class)->handle($project, ProjectReconciliationTrigger::Manual, $user);

    expect($run->project_id)->toBe($project->id)
        ->and($run->trigger)->toBe(ProjectReconciliationTrigger::Manual)
        ->and($run->status)->toBe(ProjectReconciliationStatus::Queued)
        ->and($run->requested_by_user_id)->toBe($user->id)
        ->and($project->auditEvents()->where('event_type', 'reconciliation.requested')->exists())->toBeTrue();

    Queue::assertPushed(ProcessProjectReconciliation::class, fn (ProcessProjectReconciliation $job): bool => $job->runId === $run->id);
});

test('a duplicate request while a run is active coalesces onto the same run', function () {
    Queue::fake();

    $project = reconciliationRequestProject();

    $first = app(RequestProjectReconciliation::class)->handle($project, ProjectReconciliationTrigger::Scheduled);
    $second = app(RequestProjectReconciliation::class)->handle($project, ProjectReconciliationTrigger::Manual, User::factory()->create());

    expect($second->id)->toBe($first->id)
        ->and(ProjectReconciliationRun::query()->where('project_id', $project->id)->count())->toBe(1);

    Queue::assertPushed(ProcessProjectReconciliation::class, 1);
});

test('a new request is created once the prior run is terminal', function () {
    Queue::fake();

    $project = reconciliationRequestProject();

    $first = app(RequestProjectReconciliation::class)->handle($project, ProjectReconciliationTrigger::Scheduled);
    $first->update(['status' => ProjectReconciliationStatus::Completed, 'finished_at' => now()]);

    $second = app(RequestProjectReconciliation::class)->handle($project, ProjectReconciliationTrigger::Manual);

    expect($second->id)->not->toBe($first->id)
        ->and(ProjectReconciliationRun::query()->where('project_id', $project->id)->count())->toBe(2);
});

test('the scheduled scan command and the manual controller route dispatch the identical processing job', function () {
    Queue::fake();

    $scheduledProject = reconciliationRequestProject();
    Artisan::call('aios:reconciliation:scan', ['--project' => (string) $scheduledProject->id]);

    Queue::assertPushed(ProcessProjectReconciliation::class, function (ProcessProjectReconciliation $job) use ($scheduledProject): bool {
        $run = ProjectReconciliationRun::find($job->runId);

        return $run !== null && $run->project_id === $scheduledProject->id && $run->trigger === ProjectReconciliationTrigger::Scheduled;
    });

    $manualProject = reconciliationRequestProject();
    $this->actingAs(User::factory()->create())
        ->post(route('projects.reconciliation-runs.store', $manualProject))
        ->assertRedirect(route('projects.show', $manualProject));

    Queue::assertPushed(ProcessProjectReconciliation::class, function (ProcessProjectReconciliation $job) use ($manualProject): bool {
        $run = ProjectReconciliationRun::find($job->runId);

        return $run !== null && $run->project_id === $manualProject->id && $run->trigger === ProjectReconciliationTrigger::Manual;
    });
});

test('requesting reconciliation through the controller requires authentication', function () {
    $project = reconciliationRequestProject();

    $this->post(route('projects.reconciliation-runs.store', $project))
        ->assertRedirect(route('login'));
});
