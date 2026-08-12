<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Roadmap;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\ObsidianProjectNotes;
use App\Services\StructuredResultParser;
use App\Services\WorkerHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Throwable;

class RunProjectManager
{
    public function __construct(private CodexCliRunner $runner, private AgentRunRecorder $runs, private StructuredResultParser $parser, private ApplyRoadmapPlan $plans, private ObsidianProjectNotes $notes, private WorkerHeartbeat $heartbeat, private AuditLogger $audit) {}

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function handle(Roadmap $roadmap): array
    {
        $roadmap = $this->claimRoadmap($roadmap);
        if ($roadmap === null) {
            return ['exit_code' => 0, 'output' => '', 'error_output' => ''];
        }

        $this->audit->record('roadmap.processing_started', ['roadmap_id' => $roadmap->id], $roadmap->project);
        $prompt = "You are the Project Manager. Read AGENTS.md, repository documentation, Git history, the current implementation, and the provided Obsidian project knowledge before planning. Treat Obsidian notes as context, but verify the repository before marking a task complete. Produce only JSON: {project_knowledge:{overview,architecture_decisions:[{title,rationale}],constraints,handoff},phases:[{title,objective,tasks:[{title,objective,acceptance_criteria,scope,constraints,relevant_paths,verification_commands,implementation_prompt,depends_on,completion_status,completion_evidence}]}]}. Keep tasks ordered and implementation-ready. depends_on is an array of one-based positions of earlier tasks in the complete plan; declare only real dependencies. If a task has no additional dependency, use an empty array. In project_knowledge, record only concise, verified facts that will be useful to fresh agents; do not include secrets or raw repository dumps. Use concise arrays for scope, constraints, relevant_paths, and verification_commands. Verification commands must be safe, simple commands from the approved project toolchain, with no shell operators or redirects. For every task, set completion_status to done only when the current repository already satisfies its acceptance criteria; provide concise, concrete completion_evidence with paths, commands, or commits. Otherwise set completion_status to queued and completion_evidence to null. Do not infer completion from intent or documentation alone.\n\n".json_encode(['roadmap' => $roadmap->content, 'obsidian_project_knowledge' => $this->notes->projectKnowledge($roadmap->project)], JSON_THROW_ON_ERROR);
        $run = $this->runs->start($roadmap->project, AgentRole::ProjectManager, $prompt);
        try {
            $execution = $this->runner->run($roadmap->project, $prompt, function (string $type, string $output) use ($run, $roadmap): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                $this->heartbeat->beat($roadmap->project, AgentRole::ProjectManager);
            });
        } catch (Throwable $throwable) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
        }
        $this->runs->complete($run, $execution);
        $plan = $this->parser->parse($execution['output']);

        if ($execution['exit_code'] !== 0 || $plan === null) {
            $roadmap->update(['status' => 'failed']);
            $this->audit->record('roadmap.processing_failed', ['roadmap_id' => $roadmap->id, 'exit_code' => $execution['exit_code']], $roadmap->project);

            return $execution;
        }

        try {
            $this->validatePlan($plan);
        } catch (ValidationException) {
            $roadmap->update(['status' => 'failed']);
            $this->audit->record('roadmap.processing_failed', ['roadmap_id' => $roadmap->id, 'exit_code' => $execution['exit_code'], 'reason' => 'invalid_plan'], $roadmap->project);

            return $execution;
        }

        $this->plans->handle($roadmap->project, $plan['phases']);
        $roadmap->update(['status' => 'processed', 'structured_output' => $plan, 'processed_at' => now()]);
        $this->notes->writeRoadmapPlan($roadmap->project, $plan);
        $this->notes->writeProjectManagerKnowledge($roadmap->project, $plan['project_knowledge'] ?? [], $plan['phases']);
        $this->audit->record('roadmap.processed', ['roadmap_id' => $roadmap->id, 'phase_count' => count($plan['phases'])], $roadmap->project);

        return $execution;
    }

    private function claimRoadmap(Roadmap $roadmap): ?Roadmap
    {
        return DB::transaction(function () use ($roadmap): ?Roadmap {
            $lockedRoadmap = Roadmap::query()->lockForUpdate()->findOrFail($roadmap->id);

            if ($lockedRoadmap->getRawOriginal('status') !== 'uploaded') {
                return null;
            }

            $lockedRoadmap->update(['status' => 'processing']);

            return $lockedRoadmap->refresh()->load('project');
        }, attempts: 3);
    }

    /** @param array<string, mixed> $plan */
    private function validatePlan(array $plan): void
    {
        $validator = validator($plan, [
            'project_knowledge' => ['nullable', 'array'],
            'project_knowledge.overview' => ['nullable', 'string'],
            'project_knowledge.architecture_decisions' => ['nullable', 'array'],
            'project_knowledge.architecture_decisions.*.title' => ['required', 'string', 'max:255'],
            'project_knowledge.architecture_decisions.*.rationale' => ['required', 'string'],
            'project_knowledge.constraints' => ['nullable', 'array'],
            'project_knowledge.constraints.*' => ['string'],
            'project_knowledge.handoff' => ['nullable', 'string'],
            'phases' => ['required', 'array', 'min:1'],
            'phases.*.title' => ['required', 'string', 'max:255'],
            'phases.*.objective' => ['required', 'string'],
            'phases.*.tasks' => ['required', 'array', 'min:1'],
            'phases.*.tasks.*.title' => ['required', 'string', 'max:255'],
            'phases.*.tasks.*.objective' => ['required', 'string'],
            'phases.*.tasks.*.acceptance_criteria' => ['required', 'array', 'min:1'],
            'phases.*.tasks.*.acceptance_criteria.*' => ['required', 'string'],
            'phases.*.tasks.*.scope' => ['nullable', 'array'],
            'phases.*.tasks.*.scope.*' => ['string'],
            'phases.*.tasks.*.constraints' => ['nullable', 'array'],
            'phases.*.tasks.*.constraints.*' => ['string'],
            'phases.*.tasks.*.relevant_paths' => ['nullable', 'array'],
            'phases.*.tasks.*.relevant_paths.*' => ['string'],
            'phases.*.tasks.*.verification_commands' => ['nullable', 'array'],
            'phases.*.tasks.*.verification_commands.*' => ['string'],
            'phases.*.tasks.*.implementation_prompt' => ['required', 'string'],
            'phases.*.tasks.*.depends_on' => ['nullable', 'array'],
            'phases.*.tasks.*.depends_on.*' => ['integer', 'min:1'],
            'phases.*.tasks.*.completion_status' => ['nullable', 'in:done,queued'],
            'phases.*.tasks.*.completion_evidence' => ['nullable', 'string', 'required_if:phases.*.tasks.*.completion_status,done'],
        ]);

        $validator->after(function (Validator $validator) use ($plan): void {
            $position = 0;
            foreach ($plan['phases'] as $phaseIndex => $phase) {
                foreach ($phase['tasks'] as $taskIndex => $task) {
                    $position++;
                    if (($task['completion_status'] ?? 'queued') === 'done' && blank($task['completion_evidence'] ?? null)) {
                        $validator->errors()->add("phases.{$phaseIndex}.tasks.{$taskIndex}.completion_evidence", 'Completion evidence is required before an existing task can be marked done.');
                    }

                    foreach ($task['depends_on'] ?? [] as $dependencyPosition) {
                        if ($dependencyPosition >= $position) {
                            $validator->errors()->add("phases.{$phaseIndex}.tasks.{$taskIndex}.depends_on", 'A task may only depend on an earlier task position.');
                        }
                    }

                    if (count($task['depends_on'] ?? []) !== count(array_unique($task['depends_on'] ?? []))) {
                        $validator->errors()->add("phases.{$phaseIndex}.tasks.{$taskIndex}.depends_on", 'A task dependency may only be listed once.');
                    }
                }
            }
        });

        $validator->validate();
    }
}
