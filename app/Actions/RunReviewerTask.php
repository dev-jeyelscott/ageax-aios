<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ReviewStatus;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\CodexCliRunner;
use App\Services\ObsidianProjectNotes;
use App\Services\StructuredResultParser;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskWorkflow;
use App\Services\WorkerHeartbeat;
use App\TaskStatus;
use App\WorkerLease;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunReviewerTask
{
    public function __construct(private TaskWorkflow $workflow, private CodexCliRunner $runner, private AgentRunRecorder $runs, private StructuredResultParser $parser, private TaskContextCapsuleFactory $capsules, private ObsidianProjectNotes $notes, private WorkerHeartbeat $heartbeat, private AuditLogger $audit) {}

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function run(Task $task, TaskAttempt $attempt, ?WorkerLease $lease = null): array
    {
        abort_unless(TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::Reviewing, 409, 'Only claimed review tasks may execute.');
        $task->loadMissing('project', 'phase');
        $this->audit->record('review.started', ['attempt_number' => $attempt->number], $task->project, $task);
        $context = $this->capsules->make($task, AgentRole::Reviewer);
        $prompt = "You are the Reviewer. This is a phase review: independently inspect every task in the supplied phase, repository documentation, current implementation, verification results, and exact Git diffs. Approve only when the complete phase meets its acceptance criteria; approval completes every task in the phase. If changes are needed, return actionable findings for the final task so the Coder can correct the phase in a fresh attempt. Return JSON with outcome approved|changes_required, summary, and actionable findings. When approved, make summary a concise, concrete implementation summary suitable for an Obsidian project record, covering the phase changes and verification.\n\n".json_encode(['task' => $context, 'attempt' => $attempt->only(['number', 'base_sha', 'head_sha', 'commit_sha', 'validation_results', 'changed_files']), 'phase' => $this->phaseReviewContext($task)], JSON_THROW_ON_ERROR);
        $run = $this->runs->start($task->project, AgentRole::Reviewer, $prompt, $task, $attempt, $lease);
        try {
            $execution = $this->runner->run($task->project, $prompt, function (string $type, string $output) use ($run, $task, $lease): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                if ($lease === null) {
                    $this->heartbeat->beat($task->project, AgentRole::Reviewer);
                }
            }, $lease === null ? null : fn (): bool => $this->heartbeat->renew($lease));
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
            $this->workflow->approvePhase($task, $attempt, $summary);
        } else {
            $this->workflow->transition($task, TaskStatus::ChangesRequired);
            $this->audit->record('task.rejected', ['review_id' => $review->id, 'attempt_number' => $attempt->number], $task->project, $task);
        }

        return $review;
    }

    /** @return array<string, mixed> */
    private function phaseReviewContext(Task $task): array
    {
        $phaseTasks = $task->phase_id === null
            ? collect([$task])
            : Task::query()
                ->whereBelongsTo($task->project)
                ->where('phase_id', $task->phase_id)
                ->with('attempts')
                ->orderBy('position')
                ->get();

        return [
            'title' => $task->phase?->title,
            'objective' => $task->phase?->objective,
            'tasks' => $phaseTasks->map(fn (Task $phaseTask): array => [
                'key' => $phaseTask->key,
                'title' => $phaseTask->title,
                'objective' => $phaseTask->objective,
                'acceptance_criteria' => $phaseTask->acceptance_criteria,
                'implementation_prompt' => $phaseTask->implementation_prompt,
                'attempts' => $phaseTask->attempts->map(fn (TaskAttempt $phaseAttempt): array => $phaseAttempt->only(['number', 'base_sha', 'head_sha', 'commit_sha', 'validation_results', 'changed_files']))->values()->all(),
            ])->values()->all(),
        ];
    }
}
