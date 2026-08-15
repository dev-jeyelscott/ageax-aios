<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapAttempt;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\AgentResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\ObsidianProjectNotes;
use App\Services\ProjectRuntimeCapabilityDetector;
use App\Services\StructuredResultParser;
use App\Services\WorkerHeartbeat;
use App\WorkerLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use LogicException;
use Throwable;

class RunProjectManager
{
    public function __construct(private CodexCliRunner $runner, private AgentResolver $agents, private AgentHarnessResolver $harnesses, private AgentContextAssembler $contextAssembler, private AgentRunRecorder $runs, private StructuredResultParser $parser, private ApplyRoadmapPlan $plans, private ObsidianProjectNotes $notes, private ProjectRuntimeCapabilityDetector $runtime, private WorkerHeartbeat $heartbeat, private AuditLogger $audit) {}

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function handle(Roadmap $roadmap, ?WorkerLease $lease = null): array
    {
        $attempt = $this->claimRoadmap($roadmap);
        if ($attempt === null) {
            return ['exit_code' => 0, 'output' => '', 'error_output' => ''];
        }

        $roadmap = $attempt->roadmap;

        $this->audit->record('roadmap.processing_started', ['roadmap_id' => $roadmap->id, 'roadmap_attempt_id' => $attempt->id], $roadmap->project);
        $pendingMessages = $roadmap->project->projectManagerMessages()
            ->whereNull('delivered_at')
            ->oldest()
            ->get(['id', 'body', 'created_at']);
        $retrieval = $this->notes->roadmapRetrieval($roadmap->project);
        $runtimeCapabilities = $this->runtime->detect($roadmap->project);

        // AIOS resolves the Agent bound to this workflow role for the deterministic context
        // snapshot and harness dispatch (P2-011/P2-012). Projects provisioned without a bound
        // Agent fall back to the legacy default execution path; such runs remain legacy runs.
        [$agent, $harness] = $this->resolveAgent($roadmap->project, AgentRole::ProjectManager);

        $roadmapContext = ['roadmap' => $roadmap->content, 'project_runtime_capabilities' => $runtimeCapabilities, 'obsidian_project_knowledge' => $retrieval['notes'], 'operator_messages' => $pendingMessages->map(fn ($message): array => ['id' => $message->id, 'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String()])->all()];
        $assembled = $agent === null ? null : $this->contextAssembler->assemble($agent, AgentRole::ProjectManager, $roadmapContext);
        $prompt = "You are the Project Manager. Read AGENTS.md, repository documentation, Git history, the current implementation, and the provided targeted Obsidian project knowledge before planning. Treat Obsidian notes as context, but verify the repository before marking a task complete. Treat project_runtime_capabilities as authoritative environment-topology evidence: host-only tool or PHP-extension absence does not mean a project capability is unavailable when the repository configures it in Docker Compose. For container-managed projects, prefer the repository's existing Docker Compose service conventions when generating verification_commands. Produce only JSON: {project_knowledge:{overview,architecture_decisions:[{title,rationale}],constraints,handoff},phases:[{title,objective,tasks:[{title,objective,acceptance_criteria,scope,constraints,relevant_paths,verification_commands,implementation_prompt,obsidian_notes,depends_on,completion_status,completion_evidence}]}]}. Keep tasks ordered and implementation-ready. obsidian_notes is an optional list of intentionally relevant project-local Markdown paths; never include absolute paths, traversal, or non-Markdown files. depends_on is an array of one-based positions of earlier tasks in the complete plan; declare only real dependencies. If a task has no additional dependency, use an empty array. In project_knowledge, record only concise, verified facts that will be useful to fresh agents; do not include secrets or raw repository dumps. Use concise arrays for scope, constraints, relevant_paths, and verification_commands. Verification commands must be safe, simple commands from the approved project toolchain, with no shell operators or redirects. For Docker Compose projects, prefer safe `docker compose exec -T <service> ...` verification against the detected repository-defined application service when appropriate. For every task, set completion_status to done only when the current repository already satisfies its acceptance criteria; provide concise, concrete completion_evidence with paths, commands, or commits. Otherwise set completion_status to queued and completion_evidence to null. Do not infer completion from intent or documentation alone.\n\n".json_encode($assembled?->toArray() ?? $roadmapContext, JSON_THROW_ON_ERROR);
        $run = $this->runs->start($roadmap->project, AgentRole::ProjectManager, $prompt, lease: $lease, retrievalManifest: $retrieval['manifest'], agent: $agent, context: $assembled);
        $attempt->update(['agent_run_id' => $run->id, 'status' => 'running']);
        try {
            $onOutput = function (string $type, string $output) use ($run, $roadmap, $lease): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                if ($lease === null) {
                    $this->heartbeat->beat($roadmap->project, AgentRole::ProjectManager);
                }
            };
            $onHeartbeat = $lease === null ? null : fn (): bool => $this->heartbeat->renew($lease);
            $execution = $harness !== null && $agent !== null
                ? $harness->execute($roadmap->project, $agent, $prompt, $onOutput, $onHeartbeat)->toArray()
                : $this->runner->run($roadmap->project, $prompt, $onOutput, $onHeartbeat);
        } catch (Throwable $throwable) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
        }
        $this->runs->complete($run, $execution);
        if ($execution['exit_code'] === 0) {
            $pendingMessages->each->update(['delivered_at' => now()]);
        }
        $plan = $this->parser->parse($execution['output']);

        if ($execution['exit_code'] !== 0 || $plan === null) {
            $this->failAttempt($attempt, $execution['exit_code'], 'execution_failed');

            return $execution;
        }

        try {
            $this->validatePlan($plan);
        } catch (ValidationException) {
            $this->failAttempt($attempt, $execution['exit_code'], 'invalid_plan');

            return $execution;
        }

        try {
            $this->plans->handle($roadmap->project, $plan['phases'], $roadmap, $attempt, $plan);
        } catch (Throwable) {
            $this->failAttempt($attempt, $execution['exit_code'], 'persistence_failed');

            return $execution;
        }

        $this->notes->writeRoadmapPlan($roadmap->project, $plan);
        $this->notes->writeProjectManagerKnowledge($roadmap->project, $plan['project_knowledge'] ?? [], $plan['phases']);

        return $execution;
    }

    /** @return array{0: ?Agent, 1: ?AgentHarness} */
    private function resolveAgent(Project $project, AgentRole $role): array
    {
        try {
            $agent = $this->agents->forRole($project, $role);

            return [$agent, $this->harnesses->resolve($agent)];
        } catch (LogicException) {
            return [null, null];
        }
    }

    private function claimRoadmap(Roadmap $roadmap): ?RoadmapAttempt
    {
        return DB::transaction(function () use ($roadmap): ?RoadmapAttempt {
            $lockedRoadmap = Roadmap::query()->lockForUpdate()->findOrFail($roadmap->id);

            if (! in_array($lockedRoadmap->getRawOriginal('status'), ['uploaded', 'failed'], true)) {
                return null;
            }

            $lockedRoadmap->update(['status' => 'processing']);

            $attempt = RoadmapAttempt::create([
                'roadmap_id' => $lockedRoadmap->id,
                'number' => ((int) $lockedRoadmap->attempts()->max('number')) + 1,
                'status' => 'claimed',
                'claimed_at' => now(),
            ]);
            $this->audit->record('roadmap.claimed', ['roadmap_id' => $lockedRoadmap->id, 'roadmap_attempt_id' => $attempt->id, 'attempt_number' => $attempt->number], $lockedRoadmap->project);

            return $attempt->refresh()->load('roadmap.project');
        }, attempts: 3);
    }

    private function failAttempt(RoadmapAttempt $attempt, int $exitCode, string $reason): void
    {
        DB::transaction(function () use ($attempt, $exitCode, $reason): void {
            $lockedAttempt = RoadmapAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $roadmap = Roadmap::query()->lockForUpdate()->findOrFail($lockedAttempt->roadmap_id);

            if ($lockedAttempt->getRawOriginal('status') === 'persisted') {
                return;
            }

            $lockedAttempt->update(['status' => 'failed', 'finished_at' => now()]);
            $roadmap->update(['status' => 'failed']);
            $this->audit->record('roadmap.processing_failed', ['roadmap_id' => $roadmap->id, 'roadmap_attempt_id' => $lockedAttempt->id, 'exit_code' => $exitCode, 'reason' => $reason], $roadmap->project);
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
            'phases.*.tasks.*.obsidian_notes' => ['nullable', 'array'],
            'phases.*.tasks.*.obsidian_notes.*' => ['required', 'string', 'max:255', 'regex:/^(?!\\/)(?!.*(?:\\\\|\\.\\.))[A-Za-z0-9 _.\\/-]+\\.md$/'],
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
