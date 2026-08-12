<?php

namespace App\Actions;

use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\ObsidianProjectNotes;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;

class ApplyRoadmapPlan
{
    public function __construct(private AuditLogger $audit, private ObsidianProjectNotes $notes) {}

    /** @param array<int, array{title: string, objective: string, tasks: array<int, array{title: string, objective: string, acceptance_criteria: array<int, string>, scope?: array<int, string>, constraints?: array<int, string>, relevant_paths?: array<int, string>, verification_commands?: array<int, string>, implementation_prompt: string, depends_on?: array<int, int>, completion_status?: 'done'|'queued', completion_evidence?: string|null}>}> $phases */
    public function handle(Project $project, array $phases): void
    {
        $completedTasks = DB::transaction(function () use ($project, $phases): array {
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
                    $task = Task::create(['project_id' => $project->id, 'phase_id' => $phase->id, 'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT), 'position' => $position, 'title' => $taskData['title'], 'objective' => $taskData['objective'], 'acceptance_criteria' => $taskData['acceptance_criteria'], 'scope' => $taskData['scope'] ?? [], 'constraints' => $taskData['constraints'] ?? [], 'relevant_paths' => $taskData['relevant_paths'] ?? [], 'verification_commands' => $taskData['verification_commands'] ?? [], 'implementation_prompt' => $taskData['implementation_prompt'], 'context_capsule' => ['phase' => $phaseData['title'], 'objective' => $taskData['objective'], 'acceptance_criteria' => $taskData['acceptance_criteria'], 'scope' => $taskData['scope'] ?? [], 'constraints' => $taskData['constraints'] ?? [], 'relevant_paths' => $taskData['relevant_paths'] ?? [], 'verification_commands' => $taskData['verification_commands'] ?? [], 'completion_evidence' => $taskData['completion_evidence'] ?? null], 'status' => $completed ? TaskStatus::Done : TaskStatus::Queued, 'completed_at' => $completed ? now() : null]);
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

            return $completedTasks;
        }, attempts: 3);

        foreach ($completedTasks as [$task, $completionEvidence]) {
            $this->notes->writeTaskCompletion($task, $completionEvidence);
        }
    }
}
