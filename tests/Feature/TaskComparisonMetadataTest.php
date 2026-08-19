<?php

use App\Actions\ConvertTicketToTask;
use App\Actions\RunProjectManager;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use App\TicketCategory;
use App\TicketDecision;
use App\TicketPriority;
use App\TicketStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    config()->set('aios.obsidian_vault_path', null);
});

function p3MetadataProject(
    string $name = 'Task Comparison Metadata Project',
    ProjectStatus $status = ProjectStatus::Paused,
): Project {
    $path = sys_get_temp_dir().'/ageax-p3-metadata-'.Str::uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);

    return Project::factory()->create([
        'name' => $name,
        'path' => $path,
        'status' => $status,
        'git_status' => 'clean',
    ]);
}

function p3MetadataPhase(Project $project, int $position = 1): Phase
{
    return Phase::create([
        'project_id' => $project->id,
        'position' => $position,
        'title' => "Metadata Phase {$position}",
        'objective' => 'Provide deterministic metadata coverage.',
    ]);
}

function p3MetadataTask(
    Project $project,
    ?Phase $phase = null,
    int $position = 1,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase?->id,
        'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Metadata Task {$position}",
        'objective' => 'Preserve normal Task workflow behavior.',
        'acceptance_criteria' => ['Task remains executable normally.'],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => [],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement the bounded task.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/** @return array<string, mixed> */
function p3MetadataRoadmapPlan(
    string $workType = 'feature',
    string $complexity = 'medium',
): array {
    return [
        'project_knowledge' => [
            'overview' => 'A focused roadmap metadata proof.',
        ],
        'phases' => [[
            'title' => 'Metadata Foundation',
            'objective' => 'Persist comparable Task metadata.',
            'tasks' => [[
                'title' => 'Add comparable Task metadata',
                'objective' => 'Persist descriptive work classification.',
                'work_type' => $workType,
                'complexity' => $complexity,
                'acceptance_criteria' => [
                    'The Task retains validated comparison metadata.',
                ],
                'scope' => ['app/Models/Task.php'],
                'constraints' => [
                    'Metadata must not affect workflow ordering.',
                ],
                'relevant_paths' => ['app/Models/Task.php'],
                'verification_commands' => [],
                'implementation_prompt' => 'Persist the descriptive metadata only.',
                'obsidian_notes' => [],
                'depends_on' => [],
                'completion_status' => 'queued',
                'completion_evidence' => null,
            ]],
        ]],
        'remaining_work' => false,
    ];
}

/** @return array<string, mixed> */
function p3MetadataTicketDecision(
    Phase $phase,
    TicketCategory $category = TicketCategory::Feature,
    string $complexity = 'medium',
): array {
    return [
        'category' => $category->value,
        'decision' => TicketDecision::Approved->value,
        'confidence' => 0.95,
        'summary' => 'The Ticket is clear, bounded, and safe to implement.',
        'documentation_alignment' => [],
        'affected_areas' => ['app/Actions'],
        'complexity' => $complexity,
        'requester_reply' => 'The request is approved for implementation.',
        'internal_reason_summary' => 'One bounded low-risk Task is sufficient.',
        'questions' => [],
        'blockers' => [],
        'duplicate_ticket_id' => null,
        'suggested_priority' => TicketPriority::Normal->value,
        'implementation_required' => true,
        'proposed_task' => [
            'title' => 'Implement approved Ticket metadata work',
            'objective' => 'Implement the single bounded Ticket request safely.',
            'acceptance_criteria' => [
                'The approved Ticket behavior is implemented.',
            ],
            'scope' => ['app/Actions'],
            'constraints' => [
                'Preserve deterministic AIOS workflow ownership.',
            ],
            'relevant_paths' => ['app/Actions'],
            'verification_commands' => [],
            'implementation_prompt' => 'Implement the approved Ticket change.',
            'depends_on_task_ids' => [],
            'preferred_phase_id' => $phase->id,
        ],
        'escalation_flags' => [],
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => false,
            'automatic_task_conversion_eligible' => true,
            'escalation_reasons' => [],
        ],
    ];
}

test('historical Tasks remain valid with null comparison metadata', function () {
    $project = p3MetadataProject();
    $phase = p3MetadataPhase($project);
    $task = p3MetadataTask($project, $phase);

    expect($task->refresh()->work_type)
        ->toBeNull()
        ->and($task->complexity)
        ->toBeNull();
});

test('roadmap Project Manager metadata is validated and persisted for future Tasks', function () {
    $project = p3MetadataProject(status: ProjectStatus::Running);
    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'metadata-roadmap.md',
        'storage_path' => 'roadmaps/metadata-roadmap.md',
        'status' => 'uploaded',
        'content' => 'Add one future feature Task with medium complexity.',
    ]);
    $plan = p3MetadataRoadmapPlan();

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (
            Project $runProject,
            string $prompt,
        ) use ($project, $plan): array {
            expect($runProject->id)
                ->toBe($project->id)
                ->and($prompt)
                ->toContain('work_type')
                ->toContain('complexity')
                ->toContain('descriptive analytics metadata only');

            return [
                'exit_code' => 0,
                'output' => json_encode([
                    'type' => 'item.completed',
                    'item' => [
                        'type' => 'agent_message',
                        'text' => json_encode($plan, JSON_THROW_ON_ERROR),
                    ],
                ], JSON_THROW_ON_ERROR),
                'error_output' => '',
            ];
        });

    app(RunProjectManager::class)->handle($roadmap);

    $task = $project->tasks()->sole();

    expect($task->work_type)
        ->toBe(TaskWorkType::Feature)
        ->and($task->complexity)
        ->toBe(TaskComplexity::Medium)
        ->and($task->status)
        ->toBe(TaskStatus::Queued)
        ->and($roadmap->refresh()->status)
        ->toBe('processed');
});

test('unknown roadmap comparison metadata is rejected before durable plan persistence', function (
    string $workType,
    string $complexity,
) {
    $project = p3MetadataProject(status: ProjectStatus::Running);
    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'invalid-metadata-roadmap.md',
        'storage_path' => 'roadmaps/invalid-metadata-roadmap.md',
        'status' => 'uploaded',
        'content' => 'Return one Task using invalid comparison metadata.',
    ]);
    $plan = p3MetadataRoadmapPlan($workType, $complexity);

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn([
            'exit_code' => 0,
            'output' => json_encode([
                'type' => 'item.completed',
                'item' => [
                    'type' => 'agent_message',
                    'text' => json_encode($plan, JSON_THROW_ON_ERROR),
                ],
            ], JSON_THROW_ON_ERROR),
            'error_output' => '',
        ]);

    app(RunProjectManager::class)->handle($roadmap);

    expect($project->phases()->count())
        ->toBe(0)
        ->and($project->tasks()->count())
        ->toBe(0)
        ->and($roadmap->refresh()->status)
        ->toBe('failed')
        ->and($roadmap->attempts()->latest('number')->value('status'))
        ->toBe('failed');
})->with([
    'unknown work type' => ['maintenance', 'medium'],
    'unknown complexity' => ['feature', 'extreme'],
]);

test('Ticket generated Tasks inherit validated category and complexity inside the conversion boundary', function () {
    $project = p3MetadataProject();
    $phase = p3MetadataPhase($project);
    p3MetadataTask($project, $phase);
    $ticket = Ticket::factory()
        ->for($project)
        ->create([
            'status' => TicketStatus::Triaging,
        ]);
    $attempt = TicketTriageAttempt::create([
        'ticket_id' => $ticket->id,
        'number' => 1,
        'status' => 'completed',
        'structured_decision' => p3MetadataTicketDecision(
            $phase,
            TicketCategory::Feature,
            TaskComplexity::Medium->value,
        ),
        'claimed_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)
        ->not->toBeNull()
        ->and($task?->work_type)
        ->toBe(TaskWorkType::Feature)
        ->and($task?->complexity)
        ->toBe(TaskComplexity::Medium)
        ->and($task?->originTicket()->value('tickets.id'))
        ->toBe($ticket->id)
        ->and($ticket->refresh()->converted_task_id)
        ->toBe($task?->id)
        ->and($project->tasks()->count())
        ->toBe(2);
});

test('existing Ticket to Task origin linkage rejects cross project Tasks', function () {
    $ticketProject = p3MetadataProject('Ticket Project');
    $taskProject = p3MetadataProject('Foreign Task Project');
    $foreignTask = p3MetadataTask($taskProject);
    $ticket = Ticket::factory()->for($ticketProject)->create();

    expect(
        fn () => $ticket
            ->forceFill(['converted_task_id' => $foreignTask->id])
            ->save(),
    )->toThrow(LogicException::class);
});
