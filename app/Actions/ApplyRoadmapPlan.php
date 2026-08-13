<?php

namespace App\Actions;

use App\Models\Phase;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapAttempt;
use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\ObsidianProjectNotes;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;

class ApplyRoadmapPlan
{
    public function __construct(private AuditLogger $audit, private ObsidianProjectNotes $notes) {}

    /**
     * @param  array<int, array{title: string, objective: string, tasks: array<int, array{title: string, objective: string, acceptance_criteria: array<int, string>, scope?: array<int, string>, constraints?: array<int, string>, relevant_paths?: array<int, string>, verification_commands?: array<int, string>, implementation_prompt: string, obsidian_notes?: array<int, string>, depends_on?: array<int, int>, completion_status?: 'done'|'queued', completion_evidence?: string|null}>}>  $phases
     * @param  ?array<string, mixed>  $structuredOutput
     */
    public function handle(Project $project, array $phases, ?Roadmap $roadmap = null, ?RoadmapAttempt $attempt = null, ?array $structuredOutput = null): void
    {
        $result = DB::transaction(function () use ($project, $phases, $roadmap, $attempt, $structuredOutput): array {
            if ($roadmap !== null && $attempt !== null) {
                $lockedRoadmap = Roadmap::query()->lockForUpdate()->findOrFail($roadmap->id);
                $lockedAttempt = RoadmapAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

                if ($lockedAttempt->getRawOriginal('status') === 'persisted') {
                    return ['completed' => [], 'tasks' => []];
                }

                if ($lockedRoadmap->getRawOriginal('status') !== 'processing' || $lockedAttempt->roadmap_id !== $lockedRoadmap->id) {
                    throw new \LogicException('The roadmap attempt is no longer active.');
                }
            }

            $position = 0;
            $createdTasks = [];
            $taskDependencies = [];
            $completedTasks = [];
            foreach ($phases as $phasePosition => $phaseData) {
                $phase = Phase::create(['project_id' => $project->id, 'position' => $phasePosition + 1, 'title' => $phaseData['title'], 'objective' => $phaseData['objective']]);
                $this->audit->record('phase.created', ['phase_id' => $phase->id, 'position' => $phase->position, 'title' => $phase->title], $project);
                foreach ($phaseData['tasks'] as $taskData) {
                    $position++;
                    $completed = ($taskData['completion_status'] ?? 'queued') === 'done';
                    $task = Task::create(['project_id' => $project->id, 'phase_id' => $phase->id, 'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT), 'position' => $position, 'title' => $taskData['title'], 'objective' => $taskData['objective'], 'acceptance_criteria' => $taskData['acceptance_criteria'], 'scope' => $taskData['scope'] ?? [], 'constraints' => $taskData['constraints'] ?? [], 'relevant_paths' => $taskData['relevant_paths'] ?? [], 'verification_commands' => $taskData['verification_commands'] ?? [], 'implementation_prompt' => $taskData['implementation_prompt'], 'context_capsule' => ['phase' => $phaseData['title'], 'objective' => $taskData['objective'], 'acceptance_criteria' => $taskData['acceptance_criteria'], 'scope' => $taskData['scope'] ?? [], 'constraints' => $taskData['constraints'] ?? [], 'relevant_paths' => $taskData['relevant_paths'] ?? [], 'verification_commands' => $taskData['verification_commands'] ?? [], 'obsidian_notes' => $this->safeObsidianNotes($taskData['obsidian_notes'] ?? []), 'completion_evidence' => $taskData['completion_evidence'] ?? null], 'status' => $completed ? TaskStatus::Done : TaskStatus::Queued, 'completed_at' => $completed ? now() : null]);
                    $this->audit->record('task.created', ['phase_id' => $phase->id, 'position' => $task->position, 'completion_status' => $completed ? TaskStatus::Done->value : TaskStatus::Queued->value], $project, $task);

                    if ($completed) {
                        $this->audit->record('task.imported_completed', ['completion_evidence' => $taskData['completion_evidence'] ?? null], $project, $task);
                        $completedTasks[] = [$task, (string) ($taskData['completion_evidence'] ?? 'Existing implementation verified during roadmap analysis.')];
                    }

                    $createdTasks[$position] = $task;
                    $taskDependencies[$position] = $taskData['depends_on'] ?? ($position > 1 ? [$position - 1] : []);
                }
            }

            foreach ($taskDependencies as $taskPosition => $dependencyPositions) {
                $dependencyIds = collect($dependencyPositions)
                    ->map(fn (int $dependencyPosition): int => $createdTasks[$dependencyPosition]->id)
                    ->all();

                if ($dependencyIds !== []) {
                    $createdTasks[$taskPosition]->dependencies()->attach($dependencyIds);
                }
            }

            if ($roadmap !== null && $attempt !== null && $structuredOutput !== null) {
                $lockedRoadmap = Roadmap::query()->lockForUpdate()->findOrFail($roadmap->id);
                $lockedAttempt = RoadmapAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                $lockedAttempt->update(['status' => 'persisted', 'structured_output' => $structuredOutput, 'finished_at' => now()]);
                $lockedRoadmap->update(['status' => 'processed', 'structured_output' => $structuredOutput, 'processed_at' => now()]);
                $this->audit->record('roadmap.persistence_completed', ['roadmap_id' => $lockedRoadmap->id, 'roadmap_attempt_id' => $lockedAttempt->id, 'phase_count' => count($phases)], $project);
                $this->audit->record('roadmap.processed', ['roadmap_id' => $lockedRoadmap->id, 'roadmap_attempt_id' => $lockedAttempt->id, 'phase_count' => count($phases)], $project);
            }

            return ['completed' => $completedTasks, 'tasks' => array_values($createdTasks)];
        }, attempts: 3);

        foreach ($result['tasks'] as $task) {
            $this->notes->writeTaskBrief($task);
        }

        foreach ($result['completed'] as [$task, $completionEvidence]) {
            $this->notes->writeTaskCompletion($task, $completionEvidence);
        }
    }

    /** @param array<int, mixed>|mixed $paths
     * @return array<int, string>
     */
    private function safeObsidianNotes(mixed $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_unique(array_filter($paths, fn (mixed $path): bool => is_string($path) && preg_match('/^(?!\\/)(?!.*(?:\\\\|\\.\\.))[A-Za-z0-9 _.\\/-]+\\.md$/', $path) === 1)));
    }
}
