<?php

namespace App\Actions;

use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Phase;
use App\Models\Project;
use App\Models\ProjectReconciliationRun;
use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\ProjectGitState;
use App\Services\ProjectStewardshipPolicy;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use Illuminate\Support\Facades\DB;

/** Convert one operator-approved documentation proposal only when existing workflow placement is safe. */
class ConvertApprovedKnowledgeCandidateToTask
{
    public function __construct(
        private ProjectStewardshipPolicy $policy,
        private ProjectGitState $git,
        private AuditLogger $audit,
    ) {}

    public function handle(ProjectReconciliationRun $run): ?Task
    {
        return DB::transaction(function () use ($run): ?Task {
            $lockedRun = ProjectReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);
            $project = Project::query()->lockForUpdate()->findOrFail($lockedRun->project_id);

            if (! $this->policy->permitsAutomaticTaskCreation($project)) {
                return null;
            }

            $candidate = KnowledgeImprovementCandidate::query()
                ->where('project_id', $project->id)
                ->where('status', KnowledgeImprovementCandidateStatus::Approved->value)
                ->whereIn('target_type', [KnowledgeImprovementTarget::Documentation->value, KnowledgeImprovementTarget::Rule->value, KnowledgeImprovementTarget::RegressionTest->value])
                ->whereDoesntHave('stewardshipTask')
                ->orderBy('decided_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($candidate === null || $lockedRun->evaluated_head_sha === null || $candidate->source_reconciliation_run_id === null) {
                return null;
            }

            $state = $this->git->inspect($project->path);
            if (! $state['inspectable'] || ! $state['clean'] || $state['head_sha'] !== $lockedRun->evaluated_head_sha) {
                $this->audit->record('stewardship.candidate_waiting', ['candidate_id' => $candidate->id, 'reason' => 'repository_state_changed_or_dirty'], $project);

                return null;
            }

            $tasks = Task::query()->where('project_id', $project->id)->lockForUpdate()->get();
            if ($tasks->contains(fn (Task $task): bool => ! TaskStatus::from($task->getRawOriginal('status'))->isTerminal())) {
                $this->audit->record('stewardship.candidate_waiting', ['candidate_id' => $candidate->id, 'reason' => 'roadmap_or_phase_work_remains'], $project);

                return null;
            }

            $phase = Phase::query()->where('project_id', $project->id)->lockForUpdate()->orderByDesc('position')->first();
            $phase = $phase ?? Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Documentation Maintenance', 'objective' => 'Approved, governed documentation maintenance.', 'system_key' => 'documentation-maintenance']);
            $position = ((int) Task::query()->where('project_id', $project->id)->max('position')) + 1;
            $paths = $this->evidencePaths($candidate);

            $task = Task::create([
                'project_id' => $project->id,
                'knowledge_improvement_candidate_id' => $candidate->id,
                'phase_id' => $phase->id,
                'key' => 'DOC-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
                'position' => $position,
                'title' => 'Align approved documentation: '.$candidate->affected_area,
                'objective' => $candidate->proposed_change,
                'work_type' => TaskWorkType::Other,
                'complexity' => TaskComplexity::Low,
                'acceptance_criteria' => ['Change only the approved affected documentation paths.', 'Preserve governance constraints and prove the documented claim matches committed behavior.', 'Run the task-specific deterministic documentation validation and normal Git/Reviewer lifecycle.'],
                'scope' => $paths,
                'constraints' => ['No direct semantic rewrite outside this Task.', 'Do not change application behavior or workflow policy.'],
                'relevant_paths' => $paths,
                'verification_commands' => ['git diff --check'],
                'implementation_prompt' => $candidate->proposed_change,
                'context_capsule' => [],
                'stewardship_provenance' => ['candidate_id' => $candidate->id, 'candidate_fingerprint' => $candidate->fingerprint, 'source_reconciliation_run_id' => $candidate->source_reconciliation_run_id, 'source_evidence_hash' => $candidate->evidence_hash, 'reviewed_head_sha' => $lockedRun->evaluated_head_sha, 'affected_paths' => $paths],
                'status' => TaskStatus::Queued,
            ]);

            $this->audit->record('stewardship.task_created', ['candidate_id' => $candidate->id, 'task_id' => $task->id, 'reconciliation_run_id' => $lockedRun->id, 'reviewed_head_sha' => $lockedRun->evaluated_head_sha], $project, $task);

            return $task;
        }, attempts: 3);
    }

    /** @return list<string> */
    private function evidencePaths(KnowledgeImprovementCandidate $candidate): array
    {
        $evidence = $candidate->getAttribute('evidence');
        $paths = is_array($evidence) && is_array($evidence[0]['evidence_paths'] ?? null) ? $evidence[0]['evidence_paths'] : [];

        return array_values(array_unique(array_filter($paths, 'is_string')));
    }
}
