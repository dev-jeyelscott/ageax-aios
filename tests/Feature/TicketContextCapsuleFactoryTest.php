<?php

use App\Actions\RecordTicketMessage;
use App\Actions\StoreTicketAttachment;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\ProjectStatus;
use App\Services\ContextCostEstimator;
use App\Services\TicketContextCapsuleFactory;
use App\TaskStatus;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->workspaceRoot = sys_get_temp_dir()
        .'/ageax-p3-007-workspace-'
        .Str::uuid();

    $this->obsidianRoot = sys_get_temp_dir()
        .'/ageax-p3-007-obsidian-'
        .Str::uuid();

    File::ensureDirectoryExists(
        $this->workspaceRoot,
    );

    File::ensureDirectoryExists(
        $this->obsidianRoot,
    );

    config()->set(
        'aios.workspace_root',
        $this->workspaceRoot,
    );

    config()->set(
        'aios.obsidian_vault_path',
        $this->obsidianRoot,
    );

    config()->set(
        'aios.obsidian_context_max_characters',
        5000,
    );

    config()->set(
        'aios.obsidian_context_max_note_characters',
        2500,
    );

    config()->set(
        'aios.obsidian_context_max_notes',
        4,
    );

    Storage::fake('local');
});

afterEach(function (): void {
    File::deleteDirectory(
        $this->workspaceRoot,
    );

    File::deleteDirectory(
        $this->obsidianRoot,
    );
});

function p3007Project(
    string $workspaceRoot,
    string $name,
): Project {
    $path = $workspaceRoot
        .'/'
        .Str::slug($name);

    File::ensureDirectoryExists(
        $path.'/.ai/rules',
    );

    File::ensureDirectoryExists(
        $path.'/docs',
    );

    return Project::query()->create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
        'git_head_sha' => str_repeat('a', 40),
    ]);
}

function p3007Task(
    Project $project,
    int $phasePosition,
    int $taskPosition,
    string $key,
    string $title,
    string $objective,
): Task {
    $phase = Phase::query()->firstOrCreate(
        [
            'project_id' => $project->id,
            'position' => $phasePosition,
        ],
        [
            'title' => 'Phase '.$phasePosition,
            'objective' => 'Phase objective '.$phasePosition,
        ],
    );

    return Task::query()->create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => $key,
        'position' => $taskPosition,
        'title' => $title,
        'objective' => $objective,
        'acceptance_criteria' => [
            'Behavior is deterministic.',
        ],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => [
            'app/Services/SessionManager.php',
        ],
        'verification_commands' => [
            'php artisan test --compact',
        ],
        'implementation_prompt' => 'Implement the focused task.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

test(
    'ticket context is deterministic bounded attributable and project scoped',
    function () {
        $project = p3007Project(
            $this->workspaceRoot,
            'Context Project',
        );

        $otherProject = p3007Project(
            $this->workspaceRoot,
            'Other Project',
        );

        $relatedTask = p3007Task(
            $project,
            1,
            1,
            'TASK-001',
            'Fix session timeout handling',
            'Preserve session authorization while correcting session timeout behavior.',
        );

        $otherTask = p3007Task(
            $otherProject,
            1,
            1,
            'TASK-999',
            'Fix session timeout handling',
            'Preserve session authorization while correcting session timeout behavior.',
        );

        $ticket = Ticket::factory()
            ->for($project)
            ->create([
                'title' => 'Session timeout breaks authorization',
                'description' => 'Update app/Services/SessionManager.php so session timeout handling preserves authorization.',
            ]);

        $otherTicket = Ticket::factory()
            ->for($otherProject)
            ->create([
                'title' => 'Session timeout breaks authorization',
                'description' => 'Same wording must never leak into another project context.',
            ]);

        $user = User::factory()->create();
        $messages = app(
            RecordTicketMessage::class,
        );

        $messages->handle(
            $ticket,
            TicketMessageAuthorType::User,
            TicketMessageType::PublicReply,
            'Requester confirms the session timeout is reproducible after sign in.',
            $user,
        );

        $messages->handle(
            $ticket,
            TicketMessageAuthorType::User,
            TicketMessageType::InternalNote,
            'INTERNAL-ONLY: check the existing authorization contract before replying publicly.',
            $user,
        );

        app(StoreTicketAttachment::class)->handle(
            $ticket,
            UploadedFile::fake()
                ->createWithContent(
                    'session.log',
                    str_repeat(
                        'session timeout authorization evidence ',
                        400,
                    ),
                ),
            $user,
        );

        File::put(
            $project->path.'/MASTER-PROMPT.md',
            "# Project Contract\n\nNever bypass session authorization when changing session timeout behavior.\n",
        );

        File::put(
            $project->path.'/AGENTS.md',
            "# Agents\n\nDo not bypass session authorization or durable workflow validation.\n",
        );

        File::put(
            $project->path.'/docs/session.md',
            "# Session Specification\n\nSession timeout changes must preserve authorization checks.\n",
        );

        File::put(
            $project->path.'/.ai/rules/index.md',
            "# Rules\n\n| Applies to | Rule file |\n| --- | --- |\n| app/Services/** | .ai/rules/services.md |\n",
        );

        File::put(
            $project->path.'/.ai/rules/services.md',
            "# Services\n\nDo not bypass session authorization when editing session services.\n",
        );

        $obsidianProject = $this->obsidianRoot
            .'/Projects/'
            .Str::slug($project->name);

        File::ensureDirectoryExists(
            $obsidianProject.'/Task Briefs',
        );

        File::put(
            $obsidianProject.'/STATE.md',
            "# Project State\n\nCurrent task is TASK-001.\n",
        );

        File::put(
            $obsidianProject
                .'/Task Briefs/TASK-001 - fix-session-timeout-handling.md',
            "# TASK-001\n\nPreserve session authorization.\n",
        );

        $factory = app(
            TicketContextCapsuleFactory::class,
        );

        $first = $factory->make(
            $ticket->refresh(),
        );

        $second = $factory->make(
            $ticket->refresh(),
        );

        expect($first)
            ->toBe($second)
            ->and(
                strlen(
                    (string) $first['capsule_hash'],
                ),
            )
            ->toBe(64)
            ->and(
                $first['ticket']['project_id'],
            )
            ->toBe($project->id)
            ->and(
                collect(
                    $first['related_context']['tasks'],
                )->pluck('id')->all(),
            )
            ->toContain($relatedTask->id)
            ->and(
                collect(
                    $first['related_context']['tasks'],
                )->pluck('id')->all(),
            )
            ->not->toContain($otherTask->id)
            ->and(
                json_encode(
                    $first['public_conversation'],
                ),
            )
            ->not->toContain('INTERNAL-ONLY')
            ->and(
                json_encode(
                    $first['internal_notes'],
                ),
            )
            ->toContain('INTERNAL-ONLY')
            ->and(
                $first['internal_notes'][0][
                    'verbatim_public_reply_reuse_allowed'
                ],
            )
            ->toBeFalse()
            ->and(
                $first['attachments'][0][
                    'content_is_untrusted'
                ],
            )
            ->toBeTrue()
            ->and(
                mb_strlen(
                    (string) $first['attachments'][0][
                        'text_content'
                    ],
                ),
            )
            ->toBeLessThanOrEqual(8000)
            ->and(
                collect(
                    $first['approved_documentation'],
                )->pluck('path')->all(),
            )
            ->toContain(
                'MASTER-PROMPT.md',
                'AGENTS.md',
                'docs/session.md',
            )
            ->and(
                collect(
                    $first['applicable_ai_rules'],
                )->pluck('path')->all(),
            )
            ->toContain(
                '.ai/rules/index.md',
                '.ai/rules/services.md',
            )
            ->and(
                $first['documentation_conflicts'],
            )
            ->not->toBeEmpty()
            ->and(
                array_keys(
                    $first[
                        'obsidian_project_knowledge'
                    ],
                ),
            )
            ->toContain(
                'STATE.md',
                'Task Briefs/TASK-001 - fix-session-timeout-handling.md',
            )
            ->and(
                $first[
                    'project_runtime_capabilities'
                ],
            )
            ->toBeNull()
            ->and(
                $first['retrieval_manifest'][
                    'context_cost_estimator'
                ]['budget_guard_enforced'],
            )
            ->toBeFalse();

        $sourceIds = collect(
            $first['retrieval_manifest']['sources'],
        )->pluck('source_id')->all();

        expect($sourceIds)
            ->toContain(
                'ticket:'.$ticket->id,
                'task:'.$relatedTask->id,
            )
            ->and($sourceIds)
            ->not->toContain(
                'ticket:'.$otherTicket->id,
            );

        $estimate = app(
            ContextCostEstimator::class,
        )->estimate(
            'System rules.',
            [],
            [],
            $first,
        );

        expect(
            $estimate['task_core']['characters'],
        )
            ->toBeGreaterThan(0)
            ->and(
                $estimate[
                    'obsidian_context'
                ]['characters'],
            )
            ->toBeGreaterThan(0);
    },
);

test(
    'ticket conversation limits favor recent evidence and report exclusions',
    function () {
        $project = p3007Project(
            $this->workspaceRoot,
            'Bounded Context Project',
        );

        $ticket = Ticket::factory()
            ->for($project)
            ->create([
                'title' => 'Billing calculation issue',
                'description' => 'Billing calculation produces an incorrect total.',
            ]);

        $user = User::factory()->create();

        $messages = app(
            RecordTicketMessage::class,
        );

        for ($index = 1; $index <= 40; $index++) {
            $messages->handle(
                $ticket,
                TicketMessageAuthorType::User,
                TicketMessageType::PublicReply,
                'billing message '
                    .$index
                    .' '
                    .str_repeat('x', 900),
                $user,
            );
        }

        for ($index = 1; $index <= 15; $index++) {
            $messages->handle(
                $ticket,
                TicketMessageAuthorType::User,
                TicketMessageType::InternalNote,
                'billing internal '
                    .$index
                    .' '
                    .str_repeat('y', 700),
                $user,
            );
        }

        $context = app(
            TicketContextCapsuleFactory::class,
        )->make(
            $ticket->refresh(),
        );

        expect(
            $context['public_conversation'],
        )
            ->toHaveCount(18)
            ->and(
                $context['internal_notes'],
            )
            ->toHaveCount(9)
            ->and(
                collect(
                    $context['public_conversation'],
                )->last()['body'],
            )
            ->toContain('billing message 40')
            ->and(
                collect(
                    $context['internal_notes'],
                )->last()['body'],
            )
            ->toContain('billing internal 15')
            ->and(
                $context['retrieval_manifest'][
                    'exclusions'
                ]['public_messages'],
            )
            ->toBeGreaterThan(0)
            ->and(
                $context['retrieval_manifest'][
                    'exclusions'
                ]['internal_notes'],
            )
            ->toBeGreaterThan(0);

        $publicCharacters = collect(
            $context['public_conversation'],
        )->sum(
            fn (array $message): int => mb_strlen(
                (string) $message['body'],
            ),
        );

        $internalCharacters = collect(
            $context['internal_notes'],
        )->sum(
            fn (array $message): int => mb_strlen(
                (string) $message['body'],
            ),
        );

        expect($publicCharacters)
            ->toBeLessThanOrEqual(16000)
            ->and($internalCharacters)
            ->toBeLessThanOrEqual(6000);
    },
);
