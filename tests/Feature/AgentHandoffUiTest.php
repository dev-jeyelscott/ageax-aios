<?php

use App\Actions\CreateAgentHandoff;
use App\AgentHandoffStatus;
use App\AgentHandoffType;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentHandoffSchemaValidator;
use App\TaskStatus;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create one project-scoped P8-004 fixture.
 */
function p8004Project(string $name): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/ageax-p8004-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one Task whose durable handoff evidence will be inspected.
 */
function p8004Task(
    Project $project,
    string $key,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => 1,
        'title' => 'Observe durable Agent handoffs',
        'objective' => 'Expose typed Agent collaboration as workflow evidence.',
        'acceptance_criteria' => [
            'Handoffs are safely visible without introducing Agent chat.',
        ],
        'implementation_prompt' => 'Render existing durable collaboration evidence only.',
        'context_capsule' => [],
        'status' => TaskStatus::Done,
    ]);
}

/**
 * Create one completed task-scoped AgentRun that may produce a durable handoff.
 */
function p8004Run(
    Project $project,
    Task $task,
    AgentRole $role,
    int $attemptNumber,
): AgentRun {
    return AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'role' => $role->value,
        'status' => AgentRunStatus::Completed->value,
        'attempt_number' => $attemptNumber,
        'prompt_hash' => hash(
            'sha256',
            implode(':', [
                (string) $project->id,
                (string) $task->id,
                $role->value,
                (string) $attemptNumber,
                fake()->uuid(),
            ]),
        ),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
}

/**
 * Persist one valid Coder to Reviewer implementation handoff through the authoritative Action.
 */
function p8004ImplementationHandoff(
    AgentRun $sourceRun,
    string $summary,
): AgentHandoff {
    return app(CreateAgentHandoff::class)->handle(
        $sourceRun,
        AgentRole::Reviewer,
        AgentHandoffType::ImplementationHandoff,
        AgentHandoffSchemaValidator::SchemaVersion,
        [
            'summary' => $summary,
            'changed_files' => [
                'app/Models/Task.php',
                'resources/js/pages/projects/tasks/show.tsx',
            ],
            'tests_added_or_updated' => [
                'tests/Feature/AgentHandoffUiTest.php',
            ],
            'verification_attempts' => [
                'php artisan test tests/Feature/AgentHandoffUiTest.php',
            ],
            'blockers' => [],
        ],
    );
}

/**
 * Persist one valid Reviewer to Coder finding handoff through the authoritative Action.
 */
function p8004ReviewFindingHandoff(
    AgentRun $sourceRun,
): AgentHandoff {
    return app(CreateAgentHandoff::class)->handle(
        $sourceRun,
        AgentRole::Coder,
        AgentHandoffType::ReviewFinding,
        AgentHandoffSchemaValidator::SchemaVersion,
        [
            'summary' => 'One bounded correction remains.',
            'findings' => [
                [
                    'severity' => 'high',
                    'location' => 'resources/js/pages/projects/tasks/show.tsx',
                    'current_implementation' => 'The handoff evidence contract is incomplete.',
                    'expected_implementation' => 'Every required structured field is safely visible.',
                    'why_incorrect' => 'P8-004 requires typed handoff observability.',
                    'required_fix' => 'Render the missing durable evidence.',
                    'verification_requirement' => 'Run the focused handoff UI regression test.',
                    'implementation_fix_context' => 'Preserve the read-only AIOS-owned workflow boundary.',
                ],
            ],
        ],
    );
}

test('task detail exposes deterministic project-scoped durable handoff evidence', function (): void {
    $user = User::factory()->create();

    $project = p8004Project('P8-004 Evidence');
    $task = p8004Task($project, 'P8004-001');

    $coderRun = p8004Run(
        $project,
        $task,
        AgentRole::Coder,
        1,
    );

    $reviewerRun = p8004Run(
        $project,
        $task,
        AgentRole::Reviewer,
        1,
    );

    Carbon::setTestNow(
        Carbon::parse('2026-08-24 14:00:00'),
    );

    try {
        $implementationHandoff = p8004ImplementationHandoff(
            $coderRun,
            '<script>alert("handoff")</script> API_TOKEN=super-secret-value',
        );

        $reviewFindingHandoff = p8004ReviewFindingHandoff(
            $reviewerRun,
        );

        $reviewFindingHandoff->update([
            'status' => AgentHandoffStatus::Consumed->value,
            'consumed_at' => now()->addMinute(),
        ]);

        $otherTask = p8004Task(
            $project,
            'P8004-OTHER',
        );

        p8004ImplementationHandoff(
            p8004Run(
                $project,
                $otherTask,
                AgentRole::Coder,
                1,
            ),
            'Different-task evidence must not leak.',
        );

        $otherProject = p8004Project(
            'P8-004 Foreign Project',
        );

        $otherProjectTask = p8004Task(
            $otherProject,
            'P8004-FOREIGN',
        );

        $otherProjectRun = p8004Run(
            $otherProject,
            $otherProjectTask,
            AgentRole::Coder,
            1,
        );

        AgentHandoff::create([
            'project_id' => $otherProject->id,
            'task_id' => $task->id,
            'from_agent_run_id' => $otherProjectRun->id,
            'from_role' => AgentRole::Coder->value,
            'to_role' => AgentRole::Reviewer->value,
            'handoff_type' => AgentHandoffType::ImplementationHandoff->value,
            'schema_version' => AgentHandoffSchemaValidator::SchemaVersion,
            'payload' => [
                'summary' => 'Corrupted cross-project evidence must not leak.',
                'changed_files' => [],
            ],
            'content_hash' => hash(
                'sha256',
                'p8004-corrupted-cross-project-evidence',
            ),
            'status' => AgentHandoffStatus::Pending->value,
        ]);
    } finally {
        Carbon::setTestNow();
    }

    $sanitizedSummary =
        '<script>alert("handoff")</script> API_TOKEN=[REDACTED]';

    $this->actingAs($user)
        ->get(
            route(
                'projects.tasks.show',
                [$project, $task],
            ),
        )
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('project.id', $project->id)
            ->where('task.id', $task->id)
            ->has('task.handoffs', 2)
            ->where(
                'task.handoffs.0.id',
                $implementationHandoff->id,
            )
            ->where(
                'task.handoffs.0.project_id',
                $project->id,
            )
            ->where(
                'task.handoffs.0.task_id',
                $task->id,
            )
            ->where(
                'task.handoffs.0.from_agent_run_id',
                $coderRun->id,
            )
            ->where(
                'task.handoffs.0.from_role',
                AgentRole::Coder->value,
            )
            ->where(
                'task.handoffs.0.to_role',
                AgentRole::Reviewer->value,
            )
            ->where(
                'task.handoffs.0.handoff_type',
                AgentHandoffType::ImplementationHandoff->value,
            )
            ->where(
                'task.handoffs.0.schema_version',
                AgentHandoffSchemaValidator::SchemaVersion,
            )
            ->where(
                'task.handoffs.0.status',
                AgentHandoffStatus::Pending->value,
            )
            ->where(
                'task.handoffs.0.payload.summary',
                $sanitizedSummary,
            )
            ->where(
                'task.handoffs.0.content_hash',
                $implementationHandoff->content_hash,
            )
            ->where(
                'task.handoffs.0.source_run.id',
                $coderRun->id,
            )
            ->where(
                'task.handoffs.0.source_run.project_id',
                $project->id,
            )
            ->where(
                'task.handoffs.0.source_run.task_id',
                $task->id,
            )
            ->where(
                'task.handoffs.0.source_run.role',
                AgentRole::Coder->value,
            )
            ->where(
                'task.handoffs.1.id',
                $reviewFindingHandoff->id,
            )
            ->where(
                'task.handoffs.1.from_role',
                AgentRole::Reviewer->value,
            )
            ->where(
                'task.handoffs.1.to_role',
                AgentRole::Coder->value,
            )
            ->where(
                'task.handoffs.1.handoff_type',
                AgentHandoffType::ReviewFinding->value,
            )
            ->where(
                'task.handoffs.1.status',
                AgentHandoffStatus::Consumed->value,
            )
            ->has('task.handoffs.1.consumed_at')
            ->where(
                'task.handoffs.1.source_run.id',
                $reviewerRun->id,
            ));

    $this->actingAs($user)
        ->get(
            route(
                'projects.agent-runs.show',
                [$project, $coderRun],
            ),
        )
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/agent-runs/show')
            ->where(
                'agent_run.id',
                $coderRun->id,
            ));

    $this->actingAs($user)
        ->get(
            route(
                'projects.agent-runs.show',
                [$project, $otherProjectRun],
            ),
        )
        ->assertNotFound();
});

test('handoff evidence remains read-only and introduces no free-form multi-agent chat contract', function (): void {
    $chatMutationRoutes = collect(
        app('router')->getRoutes()->getRoutes(),
    )->filter(function (Route $route): bool {
        $mutationMethods = array_intersect(
            $route->methods(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
        );

        if ($mutationMethods === []) {
            return false;
        }

        $descriptor = strtolower(
            implode(' ', [
                $route->uri(),
                (string) $route->getName(),
                $route->getActionName(),
            ]),
        );

        $isCollaborationSurface =
            str_contains($descriptor, 'handoff')
            || str_contains($descriptor, 'agent');

        $isChatMutation =
            str_contains($descriptor, 'chat')
            || str_contains($descriptor, 'message')
            || str_contains($descriptor, 'reply')
            || str_contains($descriptor, 'send');

        return $isCollaborationSurface && $isChatMutation;
    });

    expect($chatMutationRoutes)->toBeEmpty();

    $source = File::get(
        resource_path(
            'js/pages/projects/tasks/show.tsx',
        ),
    );

    expect($source)
        ->toContain('Handoff evidence')
        ->toContain('task.handoffs')
        ->toContain(
            "usePoll(2_000, { only: ['task'] }, { mode: 'rest' });",
        )
        ->not->toContain('dangerouslySetInnerHTML');

    $handoffStart = strpos(
        $source,
        'function HandoffEvidenceCard',
    );

    $handoffEnd = strpos(
        $source,
        'function ReviewSummary',
        $handoffStart === false ? 0 : $handoffStart,
    );

    expect($handoffStart)->not->toBeFalse();
    expect($handoffEnd)->not->toBeFalse();

    $handoffSection = substr(
        $source,
        (int) $handoffStart,
        (int) $handoffEnd - (int) $handoffStart,
    );

    expect($handoffSection)
        ->toContain('showAgentRun({')
        ->not->toContain('<Form')
        ->not->toContain('<textarea')
        ->not->toContain('<input')
        ->not->toContain('<select')
        ->not->toContain('type="submit"')
        ->not->toContain('storeOperatorMessage');
});
