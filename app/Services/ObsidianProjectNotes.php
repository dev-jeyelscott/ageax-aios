<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Project;
use App\Models\Review;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\ReviewStatus;
use App\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

class ObsidianProjectNotes
{
    public function __construct(private Filesystem $files) {}

    public function writeOverview(Project $project): ?string
    {
        $vault = config('aios.obsidian_vault_path');
        if (! is_string($vault) || $vault === '') {
            return null;
        }

        $directory = $vault.'/Projects/'.Str::slug($project->name);
        $this->files->ensureDirectoryExists($directory);
        $path = $directory.'/Project Overview.md';
        $status = ProjectStatus::from($project->getRawOriginal('status'))->value;
        $this->files->put($path, "# {$project->name}\n\n- Path: `{$project->path}`\n- Status: {$status}\n");
        $this->writeState($project);

        return $path;
    }

    public function writeTaskCompletion(Task $task, string $implementationSummary, ?TaskAttempt $attempt = null, ?string $reviewSummary = null): ?string
    {
        $vault = config('aios.obsidian_vault_path');
        if (! is_string($vault) || $vault === '') {
            return null;
        }

        $task->loadMissing('project', 'phase');
        $directory = $vault.'/Projects/'.Str::slug($task->project->name).'/Tasks';
        $this->files->ensureDirectoryExists($directory);
        $path = $directory.'/'.$task->key.' - '.Str::slug($task->title).'.md';
        $criteria = collect($this->acceptanceCriteria($task))->map(fn (string $criterion): string => "- {$criterion}")->implode("\n");
        $attemptDetails = $attempt === null
            ? ''
            : "\n- Attempt: {$attempt->number}\n- Commit: ".($attempt->commit_sha ?? 'Not recorded');
        $reviewDetails = filled($reviewSummary) ? "\n\n## Reviewer approval\n\n{$reviewSummary}" : '';
        $completedAt = CarbonImmutable::parse($task->getRawOriginal('completed_at') ?? now())->toIso8601String();
        $content = "# {$task->key}: {$task->title}\n\n## Implementation summary\n\n{$implementationSummary}\n\n## Objective\n\n{$task->objective}\n\n## Acceptance criteria\n\n{$criteria}\n\n## Completion\n\n- Completed: ".$completedAt.$attemptDetails.$reviewDetails."\n";
        $this->files->put($path, $content);
        $this->writeState($task->project);

        return $path;
    }

    public function writeTaskBrief(Task $task): ?string
    {
        $task->loadMissing('project', 'phase');
        $criteria = collect($this->taskStringList($task, 'acceptance_criteria'))->map(fn (string $criterion): string => "- {$criterion}")->implode("\n");
        $paths = collect($this->taskStringList($task, 'relevant_paths'))->map(fn (string $path): string => "- `{$path}`")->implode("\n");
        $commands = collect($this->taskStringList($task, 'verification_commands'))->map(fn (string $command): string => "- `{$command}`")->implode("\n");
        $constraints = collect($this->taskStringList($task, 'constraints'))->map(fn (string $constraint): string => "- {$constraint}")->implode("\n");
        $context = $this->taskArray($task, 'context_capsule');
        $links = collect(is_array($context['obsidian_notes'] ?? null) ? $context['obsidian_notes'] : [])
            ->filter(fn (mixed $path): bool => is_string($path))
            ->map(fn (string $path): string => "- [[{$path}]]")
            ->implode("\n");

        try {
            $path = $this->writeProjectNote($task->project, 'Task Briefs', $this->taskBriefFilename($task), "# {$task->key}: {$task->title}\n\n## Objective\n\n{$task->objective}\n\n## Acceptance criteria\n\n{$criteria}\n\n## Relevant paths\n\n".($paths !== '' ? $paths : '- Inspect the current implementation.')."\n\n## Verification commands\n\n".($commands !== '' ? $commands : '- Run focused existing coverage.')."\n\n## Constraints\n\n".($constraints !== '' ? $constraints : '- Follow AGENTS.md and existing conventions.')."\n\n## Intentional notes\n\n".($links !== '' ? $links : '- None.')."\n");
            $this->writeState($task->project);
        } catch (Throwable) {
            return null;
        }

        return $path;
    }

    public function writeRoadmapUpload(Roadmap $roadmap): ?string
    {
        $roadmap->loadMissing('project');

        $path = $this->writeProjectNote(
            $roadmap->project,
            'Roadmaps',
            'Latest Upload.md',
            "# Roadmap: {$roadmap->original_filename}\n\n## Uploaded roadmap\n\n{$roadmap->content}\n",
        );
        $this->writeState($roadmap->project);

        return $path;
    }

    /** @param array<string, mixed> $plan */
    public function writeRoadmapPlan(Project $project, array $plan): ?string
    {
        $path = $this->writeProjectNote(
            $project,
            'Roadmaps',
            'Implementation Plan.md',
            "# Implementation Plan\n\nThis is the validated Project Manager decomposition of the latest roadmap. Verify the repository before relying on a completion status.\n\n```json\n".json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n```\n",
        );
        $this->writeState($project);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $knowledge
     * @param  array<int, array<string, mixed>>  $phases
     */
    public function writeProjectManagerKnowledge(Project $project, array $knowledge, array $phases): void
    {
        $overview = is_string($knowledge['overview'] ?? null) ? $knowledge['overview'] : 'No additional Project Manager overview was provided.';
        $constraints = collect($this->stringList($knowledge['constraints'] ?? null))
            ->map(fn (string $constraint): string => "- {$constraint}")
            ->implode("\n");
        $decisions = collect($this->architectureDecisions($knowledge['architecture_decisions'] ?? null))
            ->map(fn (array $decision): string => "## {$decision['title']}\n\n{$decision['rationale']}")
            ->implode("\n\n");
        $handoff = is_string($knowledge['handoff'] ?? null) ? $knowledge['handoff'] : 'Start with the next eligible task and verify the repository before relying on this plan.';

        $this->writeProjectNote($project, 'Roadmaps', 'Project Manager Knowledge.md', "# Project Manager Knowledge\n\n## Overview\n\n{$overview}\n\n## Constraints\n\n".($constraints !== '' ? $constraints : '- None recorded.')."\n");
        $this->writeProjectNote($project, 'Decisions', 'Project Manager Decisions.md', "# Project Manager Decisions\n\n".($decisions !== '' ? $decisions : 'No architecture decisions were recorded.')."\n");
        $this->writeProjectNote($project, 'Handoffs', 'Project Manager Handoff.md', "# Project Manager Handoff\n\n{$handoff}\n");

        foreach ($phases as $position => $phase) {
            if (! is_string($phase['title'] ?? null) || ! is_string($phase['objective'] ?? null) || ! is_array($phase['tasks'] ?? null)) {
                continue;
            }

            $tasks = collect($phase['tasks'])
                ->filter(fn (mixed $task): bool => is_array($task) && is_string($task['title'] ?? null) && is_string($task['objective'] ?? null))
                ->map(fn (array $task): string => "- **{$task['title']}** — {$task['objective']}")
                ->implode("\n");
            $filename = str_pad((string) ($position + 1), 2, '0', STR_PAD_LEFT).' - '.Str::slug($phase['title']).'.md';
            $this->writeProjectNote($project, 'Phases', $filename, "# {$phase['title']}\n\n## Objective\n\n{$phase['objective']}\n\n## Ordered tasks\n\n".($tasks !== '' ? $tasks : '- No tasks recorded.')."\n");
        }

        $this->writeState($project);
    }

    /** @return array<int, string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    /** @return array<int, array{title: string, rationale: string}> */
    private function architectureDecisions(mixed $decisions): array
    {
        if (! is_array($decisions)) {
            return [];
        }

        return array_values(array_filter($decisions, fn (mixed $decision): bool => is_array($decision) && is_string($decision['title'] ?? null) && is_string($decision['rationale'] ?? null)));
    }

    public function writeReview(Task $task, Review $review): ?string
    {
        $task->loadMissing('project');
        $review->loadMissing('findings');
        $attempt = $review->attempt()->firstOrFail();
        $findings = $review->findings
            ->map(fn ($finding): string => "### {$finding->severity}: ".($finding->location ?? 'General')."\n\n- Current: {$finding->current_implementation}\n- Expected: {$finding->expected_implementation}\n- Why: {$finding->why_incorrect}\n- Required fix: {$finding->required_fix}\n- Verify: {$finding->verification_requirement}\n")
            ->implode("\n");
        $status = ReviewStatus::from($review->getRawOriginal('status'))->value;
        $content = "# Review: {$task->key}\n\n- Outcome: {$status}\n- Attempt: {$attempt->number}\n- Commit: ".($attempt->commit_sha ?? 'Not recorded')."\n\n## Summary\n\n".($review->summary ?? 'No summary provided.')."\n";

        if ($findings !== '') {
            $content .= "\n## Findings\n\n{$findings}";
        }

        $path = $this->writeProjectNote($task->project, 'Reviews', $task->key.'.md', $content);
        $this->writeState($task->project);

        return $path;
    }

    /** @return array<string, string> */
    public function projectKnowledge(Project $project): array
    {
        return $this->roadmapKnowledge($project);
    }

    /** @return array<string, string> */
    public function roadmapKnowledge(Project $project): array
    {
        return $this->readNotes($project, [
            'STATE.md',
            'Project Overview.md',
            'Roadmaps/Latest Upload.md',
            'Roadmaps/Implementation Plan.md',
            'Roadmaps/Project Manager Knowledge.md',
            'Decisions/Project Manager Decisions.md',
            'Handoffs/Project Manager Handoff.md',
        ]);
    }

    /** @return array{notes: array<string, string>, manifest: array{role: string, selected_note_paths: array<int, string>, character_count: int, retrieval_reason: string}} */
    public function roadmapRetrieval(Project $project): array
    {
        $notes = $this->roadmapKnowledge($project);

        return [
            'notes' => $notes,
            'manifest' => [
                'role' => AgentRole::ProjectManager->value,
                'selected_note_paths' => array_keys($notes),
                'character_count' => array_sum(array_map(Str::length(...), $notes)),
                'retrieval_reason' => 'targeted project roadmap knowledge only',
            ],
        ];
    }

    /** @return array{notes: array<string, string>, manifest: array{role: string, selected_note_paths: array<int, string>, character_count: int, retrieval_reason: string}} */
    public function taskRetrieval(Task $task, AgentRole $role): array
    {
        $task->loadMissing('project');
        $initialNotes = $this->readNotes($task->project, ['STATE.md', 'Task Briefs/'.$this->taskBriefFilename($task)]);
        $explicitPaths = $this->explicitNotePaths($task, $initialNotes);
        $notes = $initialNotes + $this->readNotes($task->project, $explicitPaths, count($initialNotes));

        return [
            'notes' => $notes,
            'manifest' => [
                'role' => $role->value,
                'selected_note_paths' => array_keys($notes),
                'character_count' => array_sum(array_map(Str::length(...), $notes)),
                'retrieval_reason' => 'state, current task brief, and intentional project-local Markdown links only',
            ],
        ];
    }

    public function writeState(Project $project): ?string
    {
        $directory = $this->projectDirectory($project);
        if ($directory === null) {
            return null;
        }

        $roadmap = $project->roadmaps()->latest('id')->first(['id', 'project_id', 'original_filename', 'status', 'processed_at']);
        $task = $project->tasks()->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])->orderBy('position')->first(['id', 'project_id', 'key', 'title', 'status']);
        $projectStatus = ProjectStatus::from($project->getRawOriginal('status'))->value;
        $roadmapState = $roadmap === null
            ? '- None uploaded.'
            : "- {$roadmap->original_filename}: {$roadmap->getRawOriginal('status')}".($roadmap->processed_at === null ? '' : ' (processed '.CarbonImmutable::parse($roadmap->getRawOriginal('processed_at'))->toIso8601String().')');
        $taskState = $task === null
            ? '- No pending task.'
            : "- {$task->key}: {$task->title} ({$task->getRawOriginal('status')})";

        try {
            $this->files->ensureDirectoryExists($directory);
            $path = $directory.'/STATE.md';
            $this->files->put($path, "# Project State\n\n## Project\n\n- Status: {$projectStatus}\n- Git: ".($project->git_status ?? 'unknown')."\n\n## Latest roadmap\n\n{$roadmapState}\n\n## Next task\n\n{$taskState}\n");
        } catch (Throwable) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, string>  $initialNotes
     * @return array<int, string>
     */
    private function explicitNotePaths(Task $task, array $initialNotes): array
    {
        $paths = [];
        $contextCapsule = $this->taskArray($task, 'context_capsule');
        if (is_array($contextCapsule['obsidian_notes'] ?? null)) {
            $paths = $contextCapsule['obsidian_notes'];
        }

        foreach ($initialNotes as $content) {
            preg_match_all('/\[\[([^\]|#]+)(?:#[^\]|]*)?(?:\|[^\]]*)?\]\]/', $content, $matches);
            $paths = [...$paths, ...$matches[1]];
        }

        $normalizedPaths = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $path = trim($path);
            if ($path === '' || Str::contains($path, ['..', '\\']) || Str::startsWith($path, '/')) {
                continue;
            }

            $normalizedPaths[] = Str::endsWith($path, '.md') ? $path : $path.'.md';
        }

        $normalizedPaths = array_values(array_unique($normalizedPaths));
        usort($normalizedPaths, fn (string $left, string $right): int => [$this->notePriority($left), $left] <=> [$this->notePriority($right), $right]);

        return $normalizedPaths;
    }

    /**
     * @param  array<int, string>  $relativePaths
     * @return array<string, string>
     */
    private function readNotes(Project $project, array $relativePaths, int $alreadySelected = 0): array
    {
        $directory = $this->projectDirectory($project);
        if ($directory === null || ! $this->files->isDirectory($directory)) {
            return [];
        }

        $remainingCharacters = max(0, (int) config('aios.obsidian_context_max_characters', 24000));
        $perNoteCharacters = max(0, (int) config('aios.obsidian_context_max_note_characters', 4000));
        $maximumNotes = max(0, (int) config('aios.obsidian_context_max_notes', 12));
        $knowledge = [];

        foreach (array_unique($relativePaths) as $relativePath) {
            if (($alreadySelected + count($knowledge)) >= $maximumNotes || $remainingCharacters === 0) {
                break;
            }

            $path = $this->safeProjectNotePath($directory, $relativePath);
            if ($path === null) {
                continue;
            }

            $content = Str::substr($this->files->get($path), 0, min($perNoteCharacters, $remainingCharacters));
            if ($content === '') {
                continue;
            }

            $knowledge[$relativePath] = $content;
            $remainingCharacters -= Str::length($content);
        }

        return $knowledge;
    }

    private function notePriority(string $path): int
    {
        return match (true) {
            Str::startsWith($path, ['Specifications/', 'Architecture/']) => 1,
            Str::startsWith($path, ['Decisions/', 'ADR/']) => 2,
            Str::startsWith($path, ['Implementation/', 'Notes/']) => 3,
            default => 4,
        };
    }

    private function safeProjectNotePath(string $directory, string $relativePath): ?string
    {
        if (! Str::endsWith($relativePath, '.md') || Str::contains($relativePath, ['..', '\\']) || Str::startsWith($relativePath, '/')) {
            return null;
        }

        $projectDirectory = realpath($directory);
        $path = realpath($directory.'/'.$relativePath);
        if ($projectDirectory === false || $path === false || ! Str::startsWith($path, $projectDirectory.'/') || ! $this->files->isFile($path)) {
            return null;
        }

        return $path;
    }

    /** @return array<int, string> */
    private function acceptanceCriteria(Task $task): array
    {
        $decoded = json_decode((string) $task->getRawOriginal('acceptance_criteria'), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return [];
        }

        return array_map(fn (mixed $criterion): string => (string) $criterion, $decoded);
    }

    /** @return array<string, mixed> */
    private function taskArray(Task $task, string $attribute): array
    {
        $decoded = json_decode((string) $task->getRawOriginal($attribute), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int, string> */
    private function taskStringList(Task $task, string $attribute): array
    {
        return array_values(array_filter($this->taskArray($task, $attribute), is_string(...)));
    }

    private function projectDirectory(Project $project): ?string
    {
        $vault = config('aios.obsidian_vault_path');

        return is_string($vault) && $vault !== '' ? $vault.'/Projects/'.Str::slug($project->name) : null;
    }

    private function writeProjectNote(Project $project, string $directoryName, string $filename, string $content): ?string
    {
        $directory = $this->projectDirectory($project);
        if ($directory === null) {
            return null;
        }

        try {
            $directory .= '/'.$directoryName;
            $this->files->ensureDirectoryExists($directory);
            $path = $directory.'/'.$filename;
            $this->files->put($path, $content);
        } catch (Throwable) {
            return null;
        }

        return $path;
    }

    private function taskBriefFilename(Task $task): string
    {
        return $task->key.' - '.Str::slug($task->title).'.md';
    }
}
