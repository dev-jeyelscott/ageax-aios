<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\TaskStatus;
use App\TicketMessageType;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class TicketContextCapsuleFactory
{
    public const SchemaVersion = 1;

    private const MAX_PUBLIC_MESSAGES = 30;

    private const MAX_INTERNAL_NOTES = 10;

    private const MAX_TICKET_TITLE_CHARACTERS = 500;

    private const MAX_TICKET_DESCRIPTION_CHARACTERS = 12000;

    private const MAX_PHASE_OBJECTIVE_CHARACTERS = 2000;

    private const MAX_TASK_OBJECTIVE_CHARACTERS = 2000;

    private const MAX_MESSAGE_CHARACTERS = 3000;

    private const MAX_PUBLIC_CHARACTERS = 16000;

    private const MAX_INTERNAL_CHARACTERS = 6000;

    private const MAX_ATTACHMENTS = 12;

    private const MAX_ATTACHMENT_CHARACTERS = 8000;

    private const MAX_ATTACHMENT_TOTAL_CHARACTERS = 24000;

    private const MAX_RELATED_CANDIDATES = 50;

    private const MAX_RELATED_RESULTS = 5;

    private const MAX_DOCUMENTS = 5;

    private const MAX_RULES = 4;

    private const MAX_FILE_CHARACTERS = 5000;

    private const MAX_DOCUMENT_CHARACTERS = 18000;

    private const MAX_RULE_CHARACTERS = 14000;

    private const MAX_CONFLICTS = 8;

    private const AUTHORITATIVE_DOCUMENTS = [
        'MASTER-PROMPT.md',
        'AGENTS.md',
        'CLAUDE.md',
    ];

    private const STOP_WORDS = [
        'about',
        'after',
        'again',
        'also',
        'and',
        'are',
        'because',
        'been',
        'before',
        'being',
        'both',
        'but',
        'can',
        'could',
        'does',
        'doing',
        'during',
        'each',
        'for',
        'from',
        'have',
        'having',
        'into',
        'its',
        'just',
        'more',
        'most',
        'not',
        'only',
        'other',
        'our',
        'out',
        'over',
        'same',
        'should',
        'some',
        'such',
        'than',
        'that',
        'the',
        'their',
        'then',
        'there',
        'these',
        'they',
        'this',
        'those',
        'through',
        'under',
        'very',
        'was',
        'were',
        'what',
        'when',
        'where',
        'which',
        'while',
        'who',
        'will',
        'with',
        'would',
        'your',
    ];

    private const RUNTIME_TERMS = [
        'binary',
        'build',
        'ci',
        'cli',
        'container',
        'database',
        'deploy',
        'deployment',
        'docker',
        'environment',
        'extension',
        'mysql',
        'node',
        'php',
        'postgres',
        'python',
        'redis',
        'runtime',
        'version',
    ];

    public function __construct(
        private TicketAttachmentStorage $attachments,
        private ObsidianProjectNotes $notes,
        private ProjectRuntimeCapabilityDetector $runtime,
        private WorkspacePathResolver $paths,
        private Filesystem $files,
    ) {}

    /** @return array<string, mixed> */
    public function make(Ticket $ticket): array
    {
        $project = $ticket->project()->firstOrFail();

        $public = $this->messages(
            $ticket,
            [
                TicketMessageType::PublicReply->value,
                TicketMessageType::SystemEvent->value,
            ],
            self::MAX_PUBLIC_MESSAGES,
            self::MAX_PUBLIC_CHARACTERS,
            'public',
        );

        $internal = $this->messages(
            $ticket,
            [TicketMessageType::InternalNote->value],
            self::MAX_INTERNAL_NOTES,
            self::MAX_INTERNAL_CHARACTERS,
            'internal',
        );

        $attachments = $this->attachmentContext($ticket);

        $searchText = $this->searchText(
            $ticket,
            $public['items'],
            $internal['items'],
            $attachments['items'],
        );

        $keywords = $this->keywords($searchText);
        $related = $this->relatedContext($ticket, $project, $keywords);
        $documentation = $this->documentation(
            $project,
            $searchText,
            $keywords,
        );
        $obsidian = $this->obsidian($project, $related['tasks']);

        $includeRuntime = array_intersect(
            $keywords,
            self::RUNTIME_TERMS,
        ) !== [];

        $context = [
            'ticket_context_schema_version' => self::SchemaVersion,
            'ticket' => $this->ticketPayload($ticket),
            'public_conversation' => $public['items'],
            'internal_notes' => $internal['items'],
            'attachments' => $attachments['items'],
            'project_state' => $this->projectState($project),
            'linked_context' => $this->linkedContext(
                $ticket,
                $project,
            ),
            'related_context' => [
                'tickets' => $related['tickets'],
                'tasks' => $related['tasks'],
            ],
            'approved_documentation' => $documentation['documents'],
            'applicable_ai_rules' => $documentation['rules'],
            'documentation_conflicts' => $documentation['conflicts'],
            'obsidian_project_knowledge' => $obsidian['notes'],
            'project_runtime_capabilities' => $includeRuntime
                ? $this->runtime->detect($project)
                : null,
        ];

        $context['retrieval_manifest'] = $this->manifest(
            $ticket,
            $context,
            $public,
            $internal,
            $attachments,
            $related,
            $documentation,
            $obsidian,
            $includeRuntime,
        );

        $context['capsule_hash'] = hash(
            'sha256',
            $this->encode($context),
        );

        return $context;
    }

    /** @return array<string, mixed> */
    private function ticketPayload(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'project_id' => $ticket->project_id,
            'key' => $ticket->key,
            'title' => Str::substr(
                (string) $ticket->title,
                0,
                self::MAX_TICKET_TITLE_CHARACTERS,
            ),
            'title_truncated' => Str::length(
                (string) $ticket->title,
            ) > self::MAX_TICKET_TITLE_CHARACTERS,
            'description' => Str::substr(
                (string) $ticket->description,
                0,
                self::MAX_TICKET_DESCRIPTION_CHARACTERS,
            ),
            'description_truncated' => Str::length(
                (string) $ticket->description,
            ) > self::MAX_TICKET_DESCRIPTION_CHARACTERS,
            'request_content_is_untrusted' => true,
            'requester_category' => $this->raw(
                $ticket,
                'requester_category',
            ),
            'category' => $this->raw(
                $ticket,
                'category',
            ),
            'status' => (string) $ticket->getRawOriginal('status'),
            'decision' => $this->raw(
                $ticket,
                'decision',
            ),
            'requester_urgency' => $this->raw(
                $ticket,
                'requester_urgency',
            ),
            'ai_suggested_priority' => $this->raw(
                $ticket,
                'ai_suggested_priority',
            ),
            'final_priority' => $this->raw(
                $ticket,
                'final_priority',
            ),
            'triage_confidence' => $ticket->triage_confidence,
            'converted_task_id' => $ticket->converted_task_id,
            'awaiting_response_until' => $this->dateValue(
                $ticket,
                'awaiting_response_until',
            ),
            'triaged_at' => $this->dateValue(
                $ticket,
                'triaged_at',
            ),
            'closed_at' => $this->dateValue(
                $ticket,
                'closed_at',
            ),
            'created_at' => $this->dateValue(
                $ticket,
                'created_at',
            ),
            'updated_at' => $this->dateValue(
                $ticket,
                'updated_at',
            ),
        ];
    }

    /**
     * @param  list<string>  $types
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int
     * }
     */
    private function messages(
        Ticket $ticket,
        array $types,
        int $maxItems,
        int $maxCharacters,
        string $visibility,
    ): array {
        $query = $ticket->messages()
            ->whereIn('message_type', $types);

        $total = (clone $query)->count();
        $remaining = $maxCharacters;
        $items = [];

        foreach (
            $query
                ->latest('id')
                ->limit($maxItems)
                ->get() as $message
        ) {
            if ($remaining === 0) {
                break;
            }

            $fullBody = (string) $message->body;
            $body = Str::substr(
                $fullBody,
                0,
                min(
                    self::MAX_MESSAGE_CHARACTERS,
                    $remaining,
                ),
            );

            if ($body === '') {
                continue;
            }

            $items[] = [
                'id' => $message->id,
                'author_type' => (string) $message
                    ->getRawOriginal('author_type'),
                'message_type' => (string) $message
                    ->getRawOriginal('message_type'),
                'body' => $body,
                'body_truncated' => Str::length($fullBody)
                    > Str::length($body),
                'ai_generated' => (bool) $message->ai_generated,
                'agent_run_id' => $message->agent_run_id,
                'visibility' => $visibility,
                'content_is_untrusted' => true,
                'verbatim_public_reply_reuse_allowed' => $visibility
                    !== 'internal',
                'created_at' => $message
                    ->created_at
                    ?->toIso8601String(),
            ];

            $remaining -= Str::length($body);
        }

        return [
            'items' => array_reverse($items),
            'total' => $total,
        ];
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int
     * }
     */
    private function attachmentContext(Ticket $ticket): array
    {
        $query = $ticket->attachments()->oldest('id');
        $total = (clone $query)->count();
        $remaining = self::MAX_ATTACHMENT_TOTAL_CHARACTERS;
        $items = [];

        foreach (
            $query
                ->limit(self::MAX_ATTACHMENTS)
                ->get() as $attachment
        ) {
            $evidence = $this->attachments
                ->triageEvidence($attachment);

            $evidence['ticket_message_id'] = $attachment
                ->ticket_message_id;

            $fullText = is_string(
                $evidence['text_content'],
            )
                ? $evidence['text_content']
                : null;

            $text = $fullText === null || $remaining === 0
                ? null
                : Str::substr(
                    $fullText,
                    0,
                    min(
                        self::MAX_ATTACHMENT_CHARACTERS,
                        $remaining,
                    ),
                );

            $remaining -= Str::length($text ?? '');

            $evidence['text_content'] = $text;
            $evidence['text_truncated'] = $fullText !== null
                && Str::length($fullText)
                    > Str::length($text ?? '');

            $items[] = $evidence;
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Build deterministic Project state evidence from non-cleared operational Tasks.
     *
     * @return array<string, mixed>
     */
    private function projectState(Project $project): array
    {
        $terminal = [
            TaskStatus::Done->value,
            TaskStatus::Cancelled->value,
        ];

        $currentPhase = $project->phases()
            ->whereHas(
                'tasks',
                fn ($query) => $query
                    ->where('is_cleared', false)
                    ->whereNotIn(
                        'status',
                        $terminal,
                    ),
            )
            ->orderBy('position')
            ->first();

        $nextPhase = $currentPhase === null
            ? null
            : $project->phases()
                ->where(
                    'position',
                    '>',
                    $currentPhase->position,
                )
                ->orderBy('position')
                ->first();

        $activeTask = $project->tasks()
            ->notCleared()
            ->whereNotIn('status', $terminal)
            ->orderBy('position')
            ->first();

        $nextTask = $activeTask === null
            ? null
            : $project->tasks()
                ->notCleared()
                ->where(
                    'position',
                    '>',
                    $activeTask->position,
                )
                ->whereNotIn('status', $terminal)
                ->orderBy('position')
                ->first();

        return [
            'project_id' => $project->id,
            'status' => (string) $project
                ->getRawOriginal('status'),
            'git_status' => $project->git_status,
            'git_head_sha' => $project->git_head_sha,
            'current_phase' => $this->phase($currentPhase),
            'next_phase' => $this->phase($nextPhase),
            'active_task' => $this->task($activeTask),
            'next_task' => $this->task($nextTask),
        ];
    }

    /** @return array<string, mixed>|null */
    private function phase(?Phase $phase): ?array
    {
        if ($phase === null) {
            return null;
        }

        $query = $phase->tasks()->notCleared()->orderBy('position');
        $total = (clone $query)->count();
        $tasks = [];

        foreach ($query->limit(12)->get() as $task) {
            $tasks[] = $this->task($task);
        }

        return [
            'id' => $phase->id,
            'position' => $phase->position,
            'title' => $phase->title,
            'objective' => Str::substr(
                (string) $phase->objective,
                0,
                self::MAX_PHASE_OBJECTIVE_CHARACTERS,
            ),
            'objective_truncated' => Str::length(
                (string) $phase->objective,
            ) > self::MAX_PHASE_OBJECTIVE_CHARACTERS,
            'tasks' => $tasks,
            'tasks_truncated' => $total > count($tasks),
        ];
    }

    /** @return array<string, mixed>|null */
    private function task(?Task $task): ?array
    {
        if ($task === null) {
            return null;
        }

        return [
            'id' => $task->id,
            'key' => $task->key,
            'position' => $task->position,
            'phase_id' => $task->phase_id,
            'title' => $task->title,
            'objective' => Str::substr(
                (string) $task->objective,
                0,
                self::MAX_TASK_OBJECTIVE_CHARACTERS,
            ),
            'objective_truncated' => Str::length(
                (string) $task->objective,
            ) > self::MAX_TASK_OBJECTIVE_CHARACTERS,
            'status' => (string) $task
                ->getRawOriginal('status'),
        ];
    }

    /** @return array<string, mixed> */
    private function linkedContext(
        Ticket $ticket,
        Project $project,
    ): array {
        $task = $ticket->converted_task_id === null
            ? null
            : $project->tasks()
                ->whereKey($ticket->converted_task_id)
                ->first();

        if ($task === null) {
            return [
                'converted_task' => null,
                'dependencies' => [],
                'dependents' => [],
            ];
        }

        $dependencies = $task->dependencies()
            ->where('tasks.project_id', $project->id)
            ->orderBy('tasks.position')
            ->get()
            ->map(
                fn (Task $dependency): ?array => $this->task($dependency),
            )
            ->filter()
            ->values()
            ->all();

        $dependents = $task->dependents()
            ->where('tasks.project_id', $project->id)
            ->orderBy('tasks.position')
            ->get()
            ->map(
                fn (Task $dependent): ?array => $this->task($dependent),
            )
            ->filter()
            ->values()
            ->all();

        return [
            'converted_task' => $this->task($task),
            'dependencies' => $dependencies,
            'dependents' => $dependents,
        ];
    }

    /**
     * @param  list<string>  $keywords
     * @return array{
     *     tickets: list<array<string, mixed>>,
     *     tasks: list<array<string, mixed>>,
     *     ticket_matches: int,
     *     task_matches: int
     * }
     */
    private function relatedContext(
        Ticket $ticket,
        Project $project,
        array $keywords,
    ): array {
        $tickets = [];

        foreach (
            $project->tickets()
                ->where('id', '!=', $ticket->id)
                ->latest('id')
                ->limit(self::MAX_RELATED_CANDIDATES)
                ->get() as $candidate
        ) {
            $matched = $this->matched(
                $keywords,
                $this->keywords(
                    $candidate->title
                        .' '
                        .$candidate->description,
                ),
            );

            if ($matched === []) {
                continue;
            }

            $tickets[] = [
                'id' => $candidate->id,
                'key' => $candidate->key,
                'title' => $candidate->title,
                'status' => (string) $candidate
                    ->getRawOriginal('status'),
                'category' => $this->raw(
                    $candidate,
                    'category',
                ),
                'decision' => $this->raw(
                    $candidate,
                    'decision',
                ),
                'match_score' => count($matched),
                'matched_terms' => $matched,
            ];
        }

        $tasks = [];

        foreach (
            $project->tasks()
                ->latest('id')
                ->limit(self::MAX_RELATED_CANDIDATES)
                ->get() as $candidate
        ) {
            $matched = $this->matched(
                $keywords,
                $this->keywords(
                    $candidate->title
                        .' '
                        .$candidate->objective,
                ),
            );

            if ($matched === []) {
                continue;
            }

            $summary = $this->task($candidate);

            if ($summary !== null) {
                $tasks[] = [
                    ...$summary,
                    'match_score' => count($matched),
                    'matched_terms' => $matched,
                ];
            }
        }

        $sort = fn (
            array $left,
            array $right,
        ): int => [
            $right['match_score'],
            $right['id'],
        ] <=> [
            $left['match_score'],
            $left['id'],
        ];

        usort($tickets, $sort);
        usort($tasks, $sort);

        return [
            'tickets' => array_slice(
                $tickets,
                0,
                self::MAX_RELATED_RESULTS,
            ),
            'tasks' => array_slice(
                $tasks,
                0,
                self::MAX_RELATED_RESULTS,
            ),
            'ticket_matches' => count($tickets),
            'task_matches' => count($tasks),
        ];
    }

    /**
     * @param  list<string>  $keywords
     * @return array{
     *     documents: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     excluded_documents: list<string>,
     *     excluded_rules: list<string>
     * }
     */
    private function documentation(
        Project $project,
        string $searchText,
        array $keywords,
    ): array {
        $root = $this->paths->assertProjectPath(
            $project->path,
        );

        $explicitPaths = $this->explicitPaths($searchText);
        $documentPaths = self::AUTHORITATIVE_DOCUMENTS;

        foreach ($explicitPaths as $path) {
            if (
                Str::endsWith(
                    Str::lower($path),
                    '.md',
                )
            ) {
                $documentPaths[] = $path;
            }
        }

        $readme = $this->readSafe(
            $root,
            'README.md',
            self::MAX_FILE_CHARACTERS,
        );

        if (
            $readme !== null
            && $this->matched(
                $keywords,
                $this->keywords($readme),
            ) !== []
        ) {
            $documentPaths[] = 'README.md';
        }

        $docsDirectory = $root.'/docs';

        if ($this->files->isDirectory($docsDirectory)) {
            $docsPaths = [];

            foreach (
                $this->files->files($docsDirectory) as $file
            ) {
                if (
                    strtolower($file->getExtension())
                    === 'md'
                ) {
                    $docsPaths[] = 'docs/'
                        .$file->getFilename();
                }
            }

            sort($docsPaths, SORT_STRING);

            foreach ($docsPaths as $path) {
                $content = $this->readSafe(
                    $root,
                    $path,
                    self::MAX_FILE_CHARACTERS,
                );

                if (
                    $content !== null
                    && $this->matched(
                        $keywords,
                        $this->keywords($content),
                    ) !== []
                ) {
                    $documentPaths[] = $path;
                }
            }
        }

        $rulePaths = ['.ai/rules/index.md'];

        $index = $this->readSafe(
            $root,
            '.ai/rules/index.md',
            self::MAX_FILE_CHARACTERS,
        );

        if ($index !== null) {
            foreach (
                $this->ruleMappings($index) as $mapping
            ) {
                foreach ($explicitPaths as $path) {
                    if (
                        Str::is(
                            $mapping['glob'],
                            $path,
                        )
                    ) {
                        $rulePaths[] = $mapping['rule'];
                    }
                }
            }
        }

        $documents = $this->readBounded(
            $root,
            array_values(
                array_unique($documentPaths),
            ),
            self::MAX_DOCUMENTS,
            self::MAX_DOCUMENT_CHARACTERS,
        );

        $rules = $this->readBounded(
            $root,
            array_values(
                array_unique($rulePaths),
            ),
            self::MAX_RULES,
            self::MAX_RULE_CHARACTERS,
        );

        return [
            'documents' => $documents['items'],
            'rules' => $rules['items'],
            'conflicts' => $this->conflicts(
                $documents['items'],
                $rules['items'],
                $keywords,
            ),
            'excluded_documents' => $documents['excluded'],
            'excluded_rules' => $rules['excluded'],
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array{
     *     items: list<array<string, mixed>>,
     *     excluded: list<string>
     * }
     */
    private function readBounded(
        string $root,
        array $paths,
        int $maxFiles,
        int $maxCharacters,
    ): array {
        $remaining = $maxCharacters;
        $items = [];
        $excluded = [];

        foreach ($paths as $path) {
            if (
                count($items) >= $maxFiles
                || $remaining === 0
            ) {
                $excluded[] = $path;

                continue;
            }

            $limit = min(
                self::MAX_FILE_CHARACTERS,
                $remaining,
            );

            $full = $this->readSafe(
                $root,
                $path,
                $limit + 1,
            );

            if ($full === null || $full === '') {
                continue;
            }

            $content = Str::substr(
                $full,
                0,
                $limit,
            );

            $items[] = [
                'path' => $path,
                'content' => $content,
                'content_hash' => hash(
                    'sha256',
                    $content,
                ),
                'truncated' => Str::length($full)
                    > Str::length($content),
            ];

            $remaining -= Str::length($content);
        }

        return [
            'items' => $items,
            'excluded' => $excluded,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @param  list<array<string, mixed>>  $rules
     * @param  list<string>  $keywords
     * @return list<array<string, mixed>>
     */
    private function conflicts(
        array $documents,
        array $rules,
        array $keywords,
    ): array {
        $conflicts = [];

        foreach (
            [
                ['document', $documents],
                ['ai_rule', $rules],
            ] as [$sourceType, $sources]
        ) {
            foreach ($sources as $source) {
                foreach (
                    preg_split(
                        '/\R/u',
                        (string) $source['content'],
                    ) ?: [] as $line
                ) {
                    $line = trim(
                        (string) $line,
                        " \t\n\r\0\x0B-*#>`",
                    );

                    if (
                        $line === ''
                        || preg_match(
                            '/\b(must not|never|do not|not allowed|forbidden|out of scope)\b/i',
                            $line,
                        ) !== 1
                    ) {
                        continue;
                    }

                    $matched = $this->matched(
                        $keywords,
                        $this->keywords($line),
                    );

                    if (count($matched) < 2) {
                        continue;
                    }

                    $conflicts[] = [
                        'evidence_type' => 'potential_prohibition_overlap',
                        'source_type' => $sourceType,
                        'source_path' => $source['path'],
                        'statement' => Str::limit(
                            $line,
                            500,
                            '',
                        ),
                        'matched_terms' => $matched,
                    ];

                    if (
                        count($conflicts)
                        >= self::MAX_CONFLICTS
                    ) {
                        return $conflicts;
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param  list<array<string, mixed>>  $relatedTasks
     * @return array{
     *     notes: array<string, string>,
     *     manifest: array<string, mixed>
     * }
     */
    private function obsidian(
        Project $project,
        array $relatedTasks,
    ): array {
        $first = $relatedTasks[0] ?? null;

        if (
            is_array($first)
            && isset($first['id'])
        ) {
            $task = $project->tasks()
                ->whereKey((int) $first['id'])
                ->first();

            if ($task !== null) {
                $retrieval = $this->notes
                    ->taskRetrieval(
                        $task,
                        AgentRole::ProjectManager,
                    );

                $retrieval['manifest']['retrieval_reason']
                    = 'ticket matched a project task deterministically; reused task-scoped Obsidian retrieval';

                return $retrieval;
            }
        }

        $retrieval = $this->notes
            ->roadmapRetrieval($project);

        $notes = [];

        foreach (
            [
                'STATE.md',
                'Project Overview.md',
                'Roadmaps/Project Manager Knowledge.md',
                'Decisions/Project Manager Decisions.md',
            ] as $path
        ) {
            if (isset($retrieval['notes'][$path])) {
                $notes[$path] = $retrieval['notes'][$path];
            }
        }

        return [
            'notes' => $notes,
            'manifest' => [
                'role' => AgentRole::ProjectManager->value,
                'selected_note_paths' => array_keys($notes),
                'character_count' => array_sum(
                    array_map(
                        Str::length(...),
                        $notes,
                    ),
                ),
                'retrieval_reason' => 'bounded ticket fallback using existing project-manager Obsidian retrieval',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{items: list<array<string, mixed>>, total: int}  $public
     * @param  array{items: list<array<string, mixed>>, total: int}  $internal
     * @param  array{items: list<array<string, mixed>>, total: int}  $attachments
     * @param  array{
     *     tickets: list<array<string, mixed>>,
     *     tasks: list<array<string, mixed>>,
     *     ticket_matches: int,
     *     task_matches: int
     * }  $related
     * @param  array{
     *     documents: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     excluded_documents: list<string>,
     *     excluded_rules: list<string>
     * }  $documentation
     * @param  array{
     *     notes: array<string, string>,
     *     manifest: array<string, mixed>
     * }  $obsidian
     * @return array<string, mixed>
     */
    private function manifest(
        Ticket $ticket,
        array $context,
        array $public,
        array $internal,
        array $attachments,
        array $related,
        array $documentation,
        array $obsidian,
        bool $includeRuntime,
    ): array {
        $sources = [
            $this->source(
                'ticket',
                'ticket:'.$ticket->id,
                $context['ticket'],
                false,
            ),
            $this->source(
                'project_state',
                'project:'.$ticket->project_id,
                $context['project_state'],
                false,
            ),
            $this->source(
                'linked_context',
                'ticket_linked:'.$ticket->id,
                $context['linked_context'],
                false,
            ),
        ];

        foreach (
            $context['public_conversation'] as $message
        ) {
            $sources[] = $this->source(
                'ticket_message',
                'ticket_message:'.$message['id'],
                $message,
                (bool) $message['body_truncated'],
            );
        }

        foreach (
            $context['internal_notes'] as $message
        ) {
            $sources[] = $this->source(
                'internal_note',
                'ticket_message:'.$message['id'],
                $message,
                (bool) $message['body_truncated'],
            );
        }

        foreach (
            $context['attachments'] as $attachment
        ) {
            $sources[] = $this->source(
                'ticket_attachment',
                'ticket_attachment:'
                    .$attachment['attachment_id'],
                $attachment,
                (bool) $attachment['text_truncated'],
            );
        }

        foreach (
            $context['approved_documentation'] as $document
        ) {
            $sources[] = $this->source(
                'repository_document',
                'repository:'
                    .$ticket->project_id
                    .':'
                    .$document['path'],
                $document,
                (bool) $document['truncated'],
            );
        }

        foreach (
            $context['applicable_ai_rules'] as $rule
        ) {
            $sources[] = $this->source(
                'ai_rule',
                'repository:'
                    .$ticket->project_id
                    .':'
                    .$rule['path'],
                $rule,
                (bool) $rule['truncated'],
            );
        }

        foreach (
            $context['obsidian_project_knowledge'] as $path => $content
        ) {
            $sources[] = $this->source(
                'obsidian_note',
                'obsidian:'
                    .$ticket->project_id
                    .':'
                    .$path,
                $content,
                false,
            );
        }

        foreach (
            $context['related_context']['tickets'] as $relatedTicket
        ) {
            $sources[] = $this->source(
                'related_ticket',
                'ticket:'.$relatedTicket['id'],
                $relatedTicket,
                false,
            );
        }

        foreach (
            $context['related_context']['tasks'] as $relatedTask
        ) {
            $sources[] = $this->source(
                'related_task',
                'task:'.$relatedTask['id'],
                $relatedTask,
                false,
            );
        }

        if ($includeRuntime) {
            $sources[] = $this->source(
                'runtime_capabilities',
                'project_runtime:'.$ticket->project_id,
                $context['project_runtime_capabilities'],
                false,
            );
        }

        return [
            'schema_version' => self::SchemaVersion,
            'sources' => $sources,
            'bounds' => [
                'public_messages' => self::MAX_PUBLIC_MESSAGES,
                'public_characters' => self::MAX_PUBLIC_CHARACTERS,
                'internal_notes' => self::MAX_INTERNAL_NOTES,
                'internal_characters' => self::MAX_INTERNAL_CHARACTERS,
                'attachments' => self::MAX_ATTACHMENTS,
                'attachment_text_characters' => self::MAX_ATTACHMENT_TOTAL_CHARACTERS,
                'related_candidates_per_type' => self::MAX_RELATED_CANDIDATES,
                'related_results_per_type' => self::MAX_RELATED_RESULTS,
                'documents' => self::MAX_DOCUMENTS,
                'document_characters' => self::MAX_DOCUMENT_CHARACTERS,
                'ai_rules' => self::MAX_RULES,
                'ai_rule_characters' => self::MAX_RULE_CHARACTERS,
            ],
            'exclusions' => [
                'public_messages' => max(
                    0,
                    $public['total']
                        - count($public['items']),
                ),
                'internal_notes' => max(
                    0,
                    $internal['total']
                        - count($internal['items']),
                ),
                'attachments' => max(
                    0,
                    $attachments['total']
                        - count($attachments['items']),
                ),
                'related_tickets' => max(
                    0,
                    $related['ticket_matches']
                        - count($related['tickets']),
                ),
                'related_tasks' => max(
                    0,
                    $related['task_matches']
                        - count($related['tasks']),
                ),
                'repository_documents' => $documentation['excluded_documents'],
                'ai_rules' => $documentation['excluded_rules'],
                'runtime_capabilities' => $includeRuntime
                    ? null
                    : 'not required by bounded ticket evidence',
            ],
            'obsidian' => $obsidian['manifest'],
            'context_cost_estimator' => [
                'schema_version' => ContextCostEstimator::SchemaVersion,
                'compatibility' => 'retrieval evidence stays in task_core; obsidian_project_knowledge stays in obsidian_context',
                'budget_guard_enforced' => false,
            ],
            'character_count_before_manifest' => Str::length(
                $this->encode($context),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function source(
        string $type,
        string $id,
        mixed $content,
        bool $truncated,
    ): array {
        $encoded = $this->encode($content);

        return [
            'source_type' => $type,
            'source_id' => $id,
            'characters' => Str::length($encoded),
            'content_hash' => hash(
                'sha256',
                $encoded,
            ),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $public
     * @param  list<array<string, mixed>>  $internal
     * @param  list<array<string, mixed>>  $attachments
     */
    private function searchText(
        Ticket $ticket,
        array $public,
        array $internal,
        array $attachments,
    ): string {
        $parts = [
            Str::substr(
                (string) $ticket->title,
                0,
                self::MAX_TICKET_TITLE_CHARACTERS,
            ),
            Str::substr(
                (string) $ticket->description,
                0,
                self::MAX_TICKET_DESCRIPTION_CHARACTERS,
            ),
        ];

        foreach (
            [$public, $internal] as $messages
        ) {
            foreach ($messages as $message) {
                $parts[] = (string) $message['body'];
            }
        }

        foreach ($attachments as $attachment) {
            if (
                is_string(
                    $attachment['text_content'] ?? null,
                )
            ) {
                $parts[] = $attachment['text_content'];
            }
        }

        return implode("\n", $parts);
    }

    /** @return list<string> */
    private function keywords(string $text): array
    {
        $result = [];

        foreach (
            preg_split(
                '/[^\pL\pN]+/u',
                Str::lower($text),
            ) ?: [] as $word
        ) {
            if (
                Str::length($word) >= 4
                && ! in_array(
                    $word,
                    self::STOP_WORDS,
                    true,
                )
            ) {
                $result[$word] = true;
            }
        }

        $result = array_keys($result);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function matched(
        array $left,
        array $right,
    ): array {
        $matched = array_values(
            array_intersect($left, $right),
        );

        sort($matched, SORT_STRING);

        return $matched;
    }

    /** @return list<string> */
    private function explicitPaths(string $text): array
    {
        preg_match_all(
            '~(?<![A-Za-z0-9_.-])((?:app|resources|routes|tests|config|database|docs|\.ai)/[A-Za-z0-9_./*?-]+)~',
            $text,
            $matches,
        );

        $paths = [];

        foreach ($matches[1] as $path) {
            $path = rtrim(
                (string) $path,
                '.,:;)]}',
            );

            if (
                $path !== ''
                && ! Str::contains(
                    $path,
                    ['..', "\0", '\\'],
                )
            ) {
                $paths[$path] = true;
            }
        }

        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return list<array{
     *     glob: string,
     *     rule: string
     * }>
     */
    private function ruleMappings(string $index): array
    {
        $mappings = [];

        foreach (
            preg_split('/\R/u', $index) ?: [] as $line
        ) {
            if (
                preg_match(
                    '/^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|$/',
                    (string) $line,
                    $matches,
                ) !== 1
            ) {
                continue;
            }

            $glob = trim(
                $matches[1],
                " `\t",
            );
            $rule = trim(
                $matches[2],
                " `\t",
            );

            if (
                $glob !== ''
                && $rule !== ''
                && $glob !== 'Applies to'
                && ! Str::startsWith(
                    $glob,
                    '---',
                )
            ) {
                $mappings[] = [
                    'glob' => $glob,
                    'rule' => $rule,
                ];
            }
        }

        return $mappings;
    }

    private function readSafe(
        string $root,
        string $relativePath,
        int $maxCharacters,
    ): ?string {
        if (
            $relativePath === ''
            || Str::contains(
                $relativePath,
                ['..', "\0", '\\'],
            )
            || Str::startsWith(
                $relativePath,
                '/',
            )
        ) {
            return null;
        }

        $resolvedRoot = realpath($root);
        $path = realpath(
            $root
                .DIRECTORY_SEPARATOR
                .$relativePath,
        );

        if (
            $resolvedRoot === false
            || $path === false
            || ! Str::startsWith(
                $path,
                $resolvedRoot.DIRECTORY_SEPARATOR,
            )
            || ! $this->files->isFile($path)
        ) {
            return null;
        }

        $content = $this->files->get($path);

        return Str::substr(
            $content,
            0,
            $maxCharacters,
        );
    }

    private function dateValue(
        Ticket $ticket,
        string $attribute,
    ): ?string {
        $value = $ticket->getAttribute($attribute);

        return $value instanceof CarbonInterface
            ? $value->toIso8601String()
            : null;
    }

    private function raw(
        Ticket $ticket,
        string $attribute,
    ): ?string {
        $value = $ticket->getRawOriginal($attribute);

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        );
    }
}
