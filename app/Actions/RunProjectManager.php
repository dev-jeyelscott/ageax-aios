<?php

namespace App\Actions;

use App\AgentRole;
use App\Exceptions\AgentNotBoundToRole;
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
        $this->renewLease($lease);

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
        // A binding that exists but is disabled, missing, or otherwise misconfigured blocks
        // processing with actionable audit evidence instead of silently falling back (P2-014).
        try {
            [$agent, $harness] = $this->resolveAgent($roadmap->project, AgentRole::ProjectManager);
        } catch (LogicException $exception) {
            return $this->blockMisconfiguredAgent($attempt, $exception);
        }

        $roadmapContext = ['roadmap' => $roadmap->content, 'project_runtime_capabilities' => $runtimeCapabilities, 'obsidian_project_knowledge' => $retrieval['notes'], 'operator_messages' => $pendingMessages->map(fn ($message): array => ['id' => $message->id, 'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String()])->all()];
        $assembled = $agent === null ? null : $this->contextAssembler->assemble($agent, AgentRole::ProjectManager, $roadmapContext);
        $prompt = "You are the Project Manager. Read AGENTS.md, repository documentation, Git history, the current implementation, and the provided targeted Obsidian project knowledge before planning. Treat Obsidian notes as context, but verify the repository before marking a task complete. Treat project_runtime_capabilities as authoritative environment-topology evidence: host-only tool or PHP-extension absence does not mean a project capability is unavailable when the repository configures it in Docker Compose. For container-managed projects, prefer the repository's existing Docker Compose service conventions when generating verification_commands. Produce only JSON: {project_knowledge:{overview,architecture_decisions:[{title,rationale}],constraints,handoff},phases:[{title,objective,tasks:[{title,objective,acceptance_criteria,scope,constraints,relevant_paths,verification_commands,implementation_prompt,obsidian_notes,depends_on,completion_status,completion_evidence}]}]}. Keep tasks ordered and implementation-ready. obsidian_notes is an optional list of intentionally relevant project-local Markdown paths; never include absolute paths, traversal, or non-Markdown files. depends_on is an array of one-based positions of earlier tasks in the complete plan; declare only real dependencies. If a task has no additional dependency, use an empty array. In project_knowledge, record only concise, verified facts that will be useful to fresh agents; do not include secrets or raw repository dumps. Use concise arrays for scope, constraints, relevant_paths, and verification_commands. Verification commands must be safe, simple commands from the approved project toolchain, with no shell operators or redirects. For Docker Compose projects, prefer safe `docker compose exec -T <service> ...` verification against the detected repository-defined application service when appropriate. For every task, set completion_status to done only when the current repository already satisfies its acceptance criteria; provide concise, concrete completion_evidence with paths, commands, or commits. Otherwise set completion_status to queued and completion_evidence to null. Do not infer completion from intent or documentation alone.\n\n".json_encode($assembled?->toArray() ?? $roadmapContext, JSON_THROW_ON_ERROR);
        $run = $this->runs->start($roadmap->project, AgentRole::ProjectManager, $prompt, lease: $lease, retrievalManifest: $retrieval['manifest'], agent: $agent, context: $assembled);
        $attempt->update(['agent_run_id' => $run->id, 'status' => 'running']);
        $this->renewLease($lease);

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

        $this->renewLease($lease);
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
            $this->plans->handle($roadmap->project, $plan['phases'], $roadmap, $attempt, $plan, fn () => $this->renewLease($lease));
        } catch (Throwable) {
            $this->failAttempt($attempt, $execution['exit_code'], 'persistence_failed');

            return $execution;
        }

        $this->renewLease($lease);

        $this->notes->writeRoadmapPlan($roadmap->project, $plan);
        $this->notes->writeProjectManagerKnowledge($roadmap->project, $plan['project_knowledge'] ?? [], $plan['phases']);

        return $execution;
    }

    /**
     * The harness's own onHeartbeat callback only renews the lease while the CLI subprocess is
     * running. Plan parsing/validation/persistence (ApplyRoadmapPlan, which writes a note file
     * per task) happens outside that window and can outlast the lease TTL for large plans,
     * letting the stale-worker recovery scan steal the lease mid-run and re-open the roadmap for
     * a second, concurrent claim. Renew at each non-harness checkpoint to close that gap.
     */
    private function renewLease(?WorkerLease $lease): void
    {
        if ($lease !== null) {
            $this->heartbeat->renew($lease);
        }
    }

    /** @return array{0: ?Agent, 1: ?AgentHarness} */
    private function resolveAgent(Project $project, AgentRole $role): array
    {
        try {
            $agent = $this->agents->forRole($project, $role);
        } catch (AgentNotBoundToRole) {
            return [null, null];
        }

        return [$agent, $this->harnesses->resolve($agent)];
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    private function blockMisconfiguredAgent(RoadmapAttempt $attempt, LogicException $exception): array
    {
        DB::transaction(function () use ($attempt, $exception): void {
            $lockedAttempt = RoadmapAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $roadmap = Roadmap::query()->lockForUpdate()->findOrFail($lockedAttempt->roadmap_id);

            if ($lockedAttempt->getRawOriginal('status') === 'persisted') {
                return;
            }

            $lockedAttempt->update(['status' => 'failed', 'finished_at' => now()]);

            $retry = $this->roadmapRetryPolicy($roadmap);
            $roadmapStatus = $retry['exhausted'] ? 'blocked' : 'failed';
            $roadmap->update(['status' => $roadmapStatus]);

            $this->audit->record('roadmap.blocked_agent_misconfigured', [
                'roadmap_id' => $roadmap->id,
                'roadmap_attempt_id' => $lockedAttempt->id,
                'reason' => $exception->getMessage(),
                'retry_count' => $retry['retry_count'],
                'retry_limit' => $retry['retry_limit'],
                'roadmap_status' => $roadmapStatus,
                'action' => $retry['exhausted']
                    ? 'Resolve the bound Project Manager Agent configuration. This roadmap is blocked and requires operator intervention before another attempt.'
                    : 'Resolve the bound Project Manager Agent configuration before the next automatic retry.',
            ], $roadmap->project);

            if ($retry['exhausted']) {
                $this->recordRoadmapRetryExhausted($roadmap, $lockedAttempt, $retry, 'agent_misconfigured', -1);
            }
        }, attempts: 3);

        return ['exit_code' => -1, 'output' => '', 'error_output' => $exception->getMessage()];
    }

    private function claimRoadmap(Roadmap $roadmap): ?RoadmapAttempt
    {
        return DB::transaction(function () use ($roadmap): ?RoadmapAttempt {
            $lockedRoadmap = Roadmap::query()->lockForUpdate()->findOrFail($roadmap->id);
            $status = (string) $lockedRoadmap->getRawOriginal('status');

            if (! in_array($status, ['uploaded', 'failed'], true)) {
                return null;
            }

            if ($status === 'failed') {
                $retry = $this->roadmapRetryPolicy($lockedRoadmap);

                if ($retry['exhausted']) {
                    $latestAttempt = $lockedRoadmap->attempts()->latest('number')->first();
                    $lockedRoadmap->update(['status' => 'blocked']);
                    $this->recordRoadmapRetryExhausted($lockedRoadmap, $latestAttempt, $retry, 'retry_limit_already_reached');

                    return null;
                }
            }

            $lockedRoadmap->update(['status' => 'processing']);

            $attempt = RoadmapAttempt::create([
                'roadmap_id' => $lockedRoadmap->id,
                'number' => ((int) $lockedRoadmap->attempts()->max('number')) + 1,
                'status' => 'claimed',
                'claimed_at' => now(),
            ]);

            $this->audit->record('roadmap.claimed', [
                'roadmap_id' => $lockedRoadmap->id,
                'roadmap_attempt_id' => $attempt->id,
                'attempt_number' => $attempt->number,
            ], $lockedRoadmap->project);

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

            $retry = $this->roadmapRetryPolicy($roadmap);
            $roadmapStatus = $retry['exhausted'] ? 'blocked' : 'failed';
            $roadmap->update(['status' => $roadmapStatus]);

            $this->audit->record('roadmap.processing_failed', [
                'roadmap_id' => $roadmap->id,
                'roadmap_attempt_id' => $lockedAttempt->id,
                'exit_code' => $exitCode,
                'reason' => $reason,
                'retry_count' => $retry['retry_count'],
                'retry_limit' => $retry['retry_limit'],
                'roadmap_status' => $roadmapStatus,
            ], $roadmap->project);

            if ($retry['exhausted']) {
                $this->recordRoadmapRetryExhausted($roadmap, $lockedAttempt, $retry, $reason, $exitCode);
            }
        }, attempts: 3);
    }

    /** @return array{retry_count: int, retry_limit: int, exhausted: bool} */
    private function roadmapRetryPolicy(Roadmap $roadmap): array
    {
        $retryCount = $roadmap->attempts()->count();
        $retryLimit = max(1, (int) config('aios.max_roadmap_attempts'));

        return [
            'retry_count' => $retryCount,
            'retry_limit' => $retryLimit,
            'exhausted' => $retryCount >= $retryLimit,
        ];
    }

    /**
     * @param  array{retry_count: int, retry_limit: int, exhausted: bool}  $retry
     */
    private function recordRoadmapRetryExhausted(Roadmap $roadmap, ?RoadmapAttempt $attempt, array $retry, string $reason, ?int $exitCode = null): void
    {
        $this->audit->record('roadmap.retry_exhausted', [
            'roadmap_id' => $roadmap->id,
            'roadmap_attempt_id' => $attempt?->id,
            'attempt_number' => $attempt->number ?? $roadmap->attempts()->max('number'),
            'retry_count' => $retry['retry_count'],
            'retry_limit' => $retry['retry_limit'],
            'exit_code' => $exitCode,
            'reason' => $reason,
            'action' => 'Inspect the latest Project Manager attempt/run evidence and correct the roadmap or Project Manager configuration before retrying.',
        ], $roadmap->project);
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
            'phases.*.tasks.*.obsidian_notes.*' => ['required', 'string', 'max:255', 'regex:/^(?!\/)(?!.*(?:\\\\|\.\.))[A-Za-z0-9 _.\/-]+\.md$/'],
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
