<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Review;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\ReviewStatus;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SplFileInfo;

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

        return $path;
    }

    public function writeRoadmapUpload(Roadmap $roadmap): ?string
    {
        $roadmap->loadMissing('project');

        return $this->writeProjectNote(
            $roadmap->project,
            'Roadmaps',
            'Latest Upload.md',
            "# Roadmap: {$roadmap->original_filename}\n\n## Uploaded roadmap\n\n{$roadmap->content}\n",
        );
    }

    /** @param array<string, mixed> $plan */
    public function writeRoadmapPlan(Project $project, array $plan): ?string
    {
        return $this->writeProjectNote(
            $project,
            'Roadmaps',
            'Implementation Plan.md',
            "# Implementation Plan\n\nThis is the validated Project Manager decomposition of the latest roadmap. Verify the repository before relying on a completion status.\n\n```json\n".json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n```\n",
        );
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

        return $this->writeProjectNote($task->project, 'Reviews', $task->key.'.md', $content);
    }

    /** @return array<string, string> */
    public function projectKnowledge(Project $project): array
    {
        $directory = $this->projectDirectory($project);
        if ($directory === null || ! $this->files->isDirectory($directory)) {
            return [];
        }

        $remainingCharacters = max(0, (int) config('aios.obsidian_context_max_characters', 24000));
        $knowledge = [];
        $files = collect($this->files->allFiles($directory))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->sortBy(fn (SplFileInfo $file): string => $file->getPathname());

        foreach ($files as $file) {
            if ($remainingCharacters === 0) {
                break;
            }

            $content = $this->files->get($file->getPathname());
            $includedContent = Str::substr($content, 0, $remainingCharacters);
            if ($includedContent === '') {
                continue;
            }

            $relativePath = Str::after($file->getPathname(), $directory.'/');
            $knowledge[$relativePath] = $includedContent;
            $remainingCharacters -= Str::length($includedContent);
        }

        return $knowledge;
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

        $directory .= '/'.$directoryName;
        $this->files->ensureDirectoryExists($directory);
        $path = $directory.'/'.$filename;
        $this->files->put($path, $content);

        return $path;
    }
}
