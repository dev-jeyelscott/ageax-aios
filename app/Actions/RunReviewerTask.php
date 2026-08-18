<?php

namespace App\Actions;

use App\AgentRole;
use App\Exceptions\AgentNotBoundToRole;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ReviewStatus;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\AgentResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\DatabaseProtectionGuard;
use App\Services\ObsidianProjectNotes;
use App\Services\StructuredResultParser;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskContractGuard;
use App\Services\TaskWorkflow;
use App\Services\WorkerHeartbeat;
use App\TaskStatus;
use App\WorkerLease;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class RunReviewerTask
{
    public function __construct(private TaskWorkflow $workflow, private CodexCliRunner $runner, private AgentResolver $agents, private AgentHarnessResolver $harnesses, private AgentContextAssembler $contextAssembler, private AgentRunRecorder $runs, private StructuredResultParser $parser, private TaskContextCapsuleFactory $capsules, private TaskContractGuard $contracts, private ObsidianProjectNotes $notes, private WorkerHeartbeat $heartbeat, private AuditLogger $audit, private DatabaseProtectionGuard $databaseProtection) {}

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function run(Task $task, TaskAttempt $attempt, ?WorkerLease $lease = null): array
    {
        abort_unless(TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Reviewing, 409, 'Only claimed review tasks may execute.');
        $task->loadMissing('project', 'phase');

        $context = $this->capsules->make($task, AgentRole::Reviewer);
        $contract = $this->contracts->evaluate($task, $context, $attempt);

        if ($contract['drifted']) {
            $this->audit->record('task.contract_drift_detected', [
                'operation' => 'reviewer',
                'baseline_attempt_number' => $contract['baseline_attempt_number'],
                'baseline_fingerprint' => $contract['baseline']['fingerprint'] ?? null,
                'current_fingerprint' => $contract['current']['fingerprint'],
                'changed_inputs' => $contract['changed_inputs'],
                'recovery_pinned' => false,
            ], $task->project, $task);
            $this->workflow->transition($task, TaskStatus::Blocked);

            return [
                'exit_code' => -1,
                'output' => '',
                'error_output' => 'Task contract drift detected before Reviewer execution. Explicitly requeue/replan/rebase the task before review.',
            ];
        }

        $this->audit->record('review.started', ['attempt_number' => $attempt->number], $task->project, $task);

        // AIOS resolves the Agent bound to this workflow role for the deterministic context
        // snapshot and harness dispatch (P2-011/P2-012). Projects provisioned without a bound
        // Agent fall back to the legacy default execution path; such runs remain legacy runs.
        // A binding that exists but is disabled, missing, or otherwise misconfigured is an
        // operational reviewer failure: it retains the completed implementation, records
        // actionable audit evidence, and retries review until the bounded limit blocks for
        // operator intervention, exactly like other reviewer operational failures (P2-016).
        try {
            [$agent, $harness] = $this->resolveAgent($task->project, AgentRole::Reviewer);
        } catch (LogicException $exception) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $exception->getMessage()];
            $this->workflow->recordReviewerOperationalFailure($task, $attempt, [
                'exit_code' => $execution['exit_code'],
                'reason' => 'agent_misconfigured',
                'error' => $exception->getMessage(),
            ]);

            return $execution;
        }

        $assembled = $agent === null ? null : $this->contextAssembler->assemble($agent, AgentRole::Reviewer, $context);
        $prompt = "You are the Reviewer. This is a read-only task review: independently inspect this task, repository documentation, current implementation, verification results, and exact Git diffs. Never edit files, create tests, format code, commit, or otherwise mutate the project. Use Git to inspect the recorded base and head SHAs, but run verification commands only from the managed project checkout provided as your working directory. Do not create temporary checkouts, copy repositories or tests, or invoke Artisan/Pest from another directory: those environments do not have the managed runtime, dependencies, or assets and their failures are invalid evidence. Approve only when this task meets its acceptance criteria; approval completes this task alone. If changes are needed, return findings so the Coder can correct this task in a fresh attempt. Return exactly one JSON object with `outcome` (`approved` or `changes_required`) and `summary`. For `changes_required`, include a non-empty `findings` array. Every finding must contain these string fields: `severity`, `location`, `current_implementation`, `expected_implementation`, `why_incorrect`, `required_fix`, `verification_requirement`, and `implementation_fix_context`. Do not use `actionable_findings`, `task_key`, `path`, `lines`, `finding`, or `required_action` as substitutes. When approved, make summary a concise, concrete implementation summary suitable for an Obsidian project record, covering the task's changes and verification.\n\n".json_encode(['task' => $assembled?->toArray() ?? $context, 'attempt' => $attempt->only(['number', 'base_sha', 'head_sha', 'commit_sha', 'validation_results', 'changed_files'])], JSON_THROW_ON_ERROR);
        $run = $this->runs->start($task->project, AgentRole::Reviewer, $prompt, $task, $attempt, $lease, $context['retrieval_manifest'], $agent, $assembled);
        try {
            $this->databaseProtection->guard($task->project);
            $onOutput = function (string $type, string $output) use ($run, $task, $lease): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                if ($lease === null) {
                    $this->heartbeat->beat($task->project, AgentRole::Reviewer);
                }
            };
            $onHeartbeat = $lease === null ? null : fn (): bool => $this->heartbeat->renew($lease);
            $execution = $harness !== null && $agent !== null
                ? $harness->execute($task->project, $agent, $prompt, $onOutput, $onHeartbeat)->toArray()
                : $this->runner->run($task->project, $prompt, $onOutput, $onHeartbeat);
        } catch (Throwable $throwable) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
        }
        $this->runs->complete($run, $execution);
        if ($execution['exit_code'] === 0) {
            $task->operatorMessages()->where('recipient_role', AgentRole::Reviewer)->whereNull('delivered_at')->update(['delivered_at' => now()]);
        }

        $review = $this->parser->parseAgentMessage($execution['output']);
        if ($execution['exit_code'] !== 0 || $review === null) {
            $this->workflow->recordReviewerOperationalFailure($task, $attempt, [
                'exit_code' => $execution['exit_code'],
                'reason' => $execution['exit_code'] !== 0 ? 'execution_failed' : 'missing_structured_decision',
            ]);

            return $execution;
        }

        try {
            $validated = validator($review, [
                'outcome' => ['required', 'in:approved,changes_required'],
                'summary' => ['nullable', 'string'],
                'findings' => ['exclude_unless:outcome,changes_required', 'required', 'array', 'min:1'],
                'findings.*.severity' => ['required', 'string'],
                'findings.*.location' => ['nullable', 'string'],
                'findings.*.current_implementation' => ['required', 'string'],
                'findings.*.expected_implementation' => ['required', 'string'],
                'findings.*.why_incorrect' => ['required', 'string'],
                'findings.*.required_fix' => ['required', 'string'],
                'findings.*.verification_requirement' => ['required', 'string'],
                'findings.*.implementation_fix_context' => ['required', 'string'],
            ])->validate();
        } catch (ValidationException) {
            $this->workflow->recordReviewerOperationalFailure($task, $attempt, [
                'exit_code' => $execution['exit_code'],
                'reason' => 'invalid_structured_decision',
            ]);

            return $execution;
        }

        $this->record($task, $attempt, ReviewStatus::from($validated['outcome']), $validated['summary'] ?? null, $validated['findings'] ?? []);

        return $execution;
    }

    /** @param array<int, array<string, string>> $findings */
    private function record(Task $task, TaskAttempt $attempt, ReviewStatus $outcome, ?string $summary = null, array $findings = []): Review
    {
        abort_unless(TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Reviewing, 409, 'Only claimed review tasks may be decided.');
        abort_unless(in_array($outcome, [ReviewStatus::Approved, ReviewStatus::ChangesRequired], true), 422, 'A review must explicitly approve or request changes.');
        abort_if($outcome === ReviewStatus::ChangesRequired && $findings === [], 422, 'A rejection must include actionable findings.');
        $review = Review::create(['task_id' => $task->id, 'task_attempt_id' => $attempt->id, 'status' => $outcome, 'summary' => $summary, 'started_at' => now(), 'completed_at' => now()]);
        foreach ($findings as $finding) {
            $reviewFinding = ReviewFinding::create(['review_id' => $review->id, 'severity' => $finding['severity'], 'location' => $finding['location'] ?? null, 'current_implementation' => $finding['current_implementation'], 'expected_implementation' => $finding['expected_implementation'], 'why_incorrect' => $finding['why_incorrect'], 'required_fix' => $finding['required_fix'], 'verification_requirement' => $finding['verification_requirement'], 'implementation_fix_context' => $finding['implementation_fix_context']]);
            $this->audit->record('review.finding_recorded', [
                'review_id' => $review->id,
                'review_finding_id' => $reviewFinding->id,
                'severity' => $reviewFinding->severity,
                'location' => $reviewFinding->location,
            ], $task->project, $task);
        }
        $this->notes->writeReview($task, $review);
        $this->audit->record('review.completed', ['review_id' => $review->id, 'outcome' => $outcome->value, 'finding_count' => count($findings), 'attempt_number' => $attempt->number], $task->project, $task);

        if ($outcome === ReviewStatus::Approved) {
            $this->workflow->approveTask($task, $attempt, $summary);
        } else {
            $this->workflow->transition($task, TaskStatus::ChangesRequired);
            $this->audit->record('task.rejected', ['review_id' => $review->id, 'attempt_number' => $attempt->number], $task->project, $task);
        }

        return $review;
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
}
