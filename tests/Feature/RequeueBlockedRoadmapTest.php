<?php

use App\Actions\RequeueBlockedRoadmap;
use App\Jobs\ProcessRoadmap;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapAttempt;
use App\Models\User;
use App\ProjectStatus;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('a blocked roadmap is requeued to uploaded and dispatched for immediate reprocessing', function () {
    Queue::fake();

    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/blocked.md',
        'status' => 'blocked',
        'content' => 'Produce a valid implementation roadmap.',
    ]);
    RoadmapAttempt::create([
        'roadmap_id' => $roadmap->id,
        'number' => 1,
        'status' => 'failed',
        'claimed_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
    ]);

    app(RequeueBlockedRoadmap::class)->handle($roadmap);

    expect($roadmap->refresh()->status)->toBe('uploaded')
        ->and($roadmap->attempts()->count())->toBe(1)
        ->and($project->auditEvents()->where('event_type', 'roadmap.requeued')->exists())->toBeTrue();

    Queue::assertPushed(ProcessRoadmap::class, fn (ProcessRoadmap $job): bool => $job->roadmapId === $roadmap->id);
});

test('only a blocked roadmap may be requeued', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/failed.md',
        'status' => 'failed',
        'content' => 'Produce a valid implementation roadmap.',
    ]);

    app(RequeueBlockedRoadmap::class)->handle($roadmap);
})->throws(HttpException::class);

test('requeueing a blocked roadmap through the controller redirects back to the project', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/blocked.md',
        'status' => 'blocked',
        'content' => 'Produce a valid implementation roadmap.',
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->id}/roadmaps/{$roadmap->id}/requeue")
        ->assertRedirect("/projects/{$project->id}");

    expect($roadmap->refresh()->status)->toBe('uploaded');
});

test('requeueing a roadmap that belongs to another project 404s', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $otherProject = Project::create(['name' => 'Other', 'path' => '/tmp/other-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $roadmap = Roadmap::create([
        'project_id' => $otherProject->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/blocked.md',
        'status' => 'blocked',
        'content' => 'Produce a valid implementation roadmap.',
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->id}/roadmaps/{$roadmap->id}/requeue")
        ->assertNotFound();
});
