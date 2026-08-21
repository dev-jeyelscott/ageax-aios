<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskPlanningEscalation;
use App\Models\TaskPlanningRevisionAttempt;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\AgentResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\DatabaseProtectionGuard;
use App\Services\StructuredResultParser;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskValidator;
use App\Services\WorkerHeartbeat;
use App\TaskStatus;
use App\WorkerLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunTaskPlanningRevision
{
    public function __construct(private AgentResolver $agents, private AgentHarnessResolver $harnesses, private AgentContextAssembler $contextAssembler, private AgentRunRecorder $runs, private StructuredResultParser $parser, private TaskContextCapsuleFactory $capsules, private TaskValidator $validator, private WorkerHeartbeat $heartbeat, private AuditLogger $audit, private DatabaseProtectionGuard $databaseProtection) {}

    /** @return array{exit_code:int,output:string,error_output:string} */
    public function handle(TaskPlanningRevisionAttempt $attempt, ?WorkerLease $lease = null): array
    {
        $attempt->loadMissing('escalation.task.project');
        $escalation = $attempt->escalation;
        $task = $escalation->task;
        $project = $task->project;
        if (! in_array($attempt->status, ['claimed', 'running'], true) || $escalation->status !== 'running') {
            return $this->emptyExecution();
        }

        try {
            [$agent, $harness] = $this->resolveAgent($project);
            $context = $this->context($task, $escalation);
            $assembled = $this->contextAssembler->assemble($agent, AgentRole::ProjectManager, $context);
            $prompt = $this->prompt($assembled->toArray());
            $run = $this->runs->start($project, AgentRole::ProjectManager, $prompt, $task, null, $lease, null, $agent, $assembled);
            $attempt->update(['agent_run_id' => $run->id, 'status' => 'running']);
        } catch (Throwable $throwable) {
            $this->fail($attempt, 'context_or_agent_failure');

            return ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
        }

        try {
            $this->databaseProtection->guard($project);
            $onOutput = function (string $type, string $output) use ($run, $project, $lease): void {
                $this->runs->appendLiveOutput($run, $type, $output);
                if ($lease === null) {
                    $this->heartbeat->beat($project, AgentRole::ProjectManager);
                }
            };
            $execution = $harness->execute($project, $agent, $prompt, $onOutput, $lease === null ? null : fn (): bool => $this->heartbeat->renew($lease))->toArray();
        } catch (Throwable $throwable) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $throwable->getMessage()];
        }
        $this->runs->complete($run, $execution);
        if ($execution['exit_code'] !== 0) {
            $this->fail($attempt, 'execution_failed');

            return $execution;
        }

        $proposal = $this->parser->parseAgentMessage($execution['output']);
        try {
            if ($proposal === null) {
                throw ValidationException::withMessages(['proposal' => 'The Project Manager must return a planning_revision JSON object.']);
            }
            $this->apply($attempt, $proposal);
        } catch (Throwable) {
            $this->fail($attempt, 'invalid_revision_proposal');
        }

        return $execution;
    }

    /** @return array{0:Agent,1:AgentHarness} */
    private function resolveAgent(Project $project): array
    {
        $agent = $this->agents->forRole($project, AgentRole::ProjectManager);

        return [$agent, $this->harnesses->resolve($agent)];
    }

    /** @return array<string,mixed> */
    private function context(Task $task, TaskPlanningEscalation $escalation): array
    {
        return ['planning_revision' => ['task_key' => $task->key, 'objective' => $task->objective, 'acceptance_criteria' => $task->acceptance_criteria, 'scope' => $task->scope, 'constraints' => $task->constraints, 'relevant_paths' => $task->relevant_paths, 'verification_commands' => $task->verification_commands, 'implementation_prompt' => $task->implementation_prompt, 'dependencies' => $task->dependencies()->pluck('key')->all(), 'allowed_fields' => $escalation->allowed_fields, 'defect_type' => $escalation->defect_type, 'failure_evidence' => $escalation->failure_evidence]];
    }

    /** @param array<string,mixed> $context */
    private function prompt(array $context): string
    {
        $contract = <<<'PROMPT'
You are the Project Manager in AIOS planning_revision mode. Return one JSON object only: {"reason":"concise evidence", "replacements":{"allowed_field":"replacement value"}}. Replace only supplied allowed_fields. Do not change task identity, title, objective, phase, position, work metadata, or any field not explicitly allowed.

When replacing verification_commands, every command must be one standalone allowlisted executable beginning with exactly one of: php, composer, npm, pnpm, yarn, bun, npx, git, vendor/bin/pest, ./vendor/bin/pest, vendor/bin/phpstan, ./vendor/bin/phpstan, vendor/bin/pint, or ./vendor/bin/pint. Never use ls, rg, find, shell pipelines, redirects, command substitution, semicolons, shell operators, or database migration/destructive Artisan commands. Preserve any safe existing verification command unless replacement is necessary.
PROMPT;

        return $contract."\n\nAIOS assembled context:\n".json_encode(
            $context,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param array<string,mixed> $proposal */
    private function apply(TaskPlanningRevisionAttempt $attempt, array $proposal): void
    {
        DB::transaction(function () use ($attempt, $proposal): void {
            $lockedAttempt = TaskPlanningRevisionAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $escalation = TaskPlanningEscalation::query()->lockForUpdate()->findOrFail($lockedAttempt->task_planning_escalation_id);
            $task = Task::query()->lockForUpdate()->findOrFail($escalation->task_id);
            if ($lockedAttempt->status !== 'running' || $escalation->status !== 'running' || TaskStatus::from($task->getRawOriginal('status')) !== TaskStatus::Blocked) {
                throw ValidationException::withMessages(['attempt' => 'Planning revision is no longer active.']);
            }
            $replacements = $proposal['replacements'] ?? null;
            if (! is_string($proposal['reason'] ?? null) || trim($proposal['reason']) === '' || ! is_array($replacements) || $replacements === [] || array_diff(array_keys($replacements), $escalation->allowed_fields) !== []) {
                throw ValidationException::withMessages(['proposal' => 'Only non-empty allowlisted replacements are permitted.']);
            }
            $this->validateReplacements($task, $replacements);
            $changed = collect($replacements)->filter(fn (mixed $value, string $field): bool => $field === 'dependencies' ? $value !== $task->dependencies()->pluck('key')->all() : $value !== $task->getAttribute($field));
            if ($changed->isEmpty()) {
                throw ValidationException::withMessages(['proposal' => 'A planning revision must change an allowed field.']);
            }
            $attributes = $changed->except('dependencies')->all();
            if ($attributes !== []) {
                $task->update($attributes);
            }
            if ($changed->has('dependencies')) {
                $task->dependencies()->sync(Task::query()->where('project_id', $task->project_id)->whereIn('key', $changed['dependencies'])->pluck('id'));
            }
            $task->update(['context_capsule' => $this->capsules->make($task->refresh())]);
            $lockedAttempt->update(['status' => 'applied', 'proposal' => ['reason' => $proposal['reason'], 'replacements' => $changed->all()], 'finished_at' => now()]);
            $escalation->update(['status' => 'resolved', 'resolved_at' => now()]);
            $task->update(['status' => TaskStatus::ChangesRequired]);
            $this->audit->record('task.planning_revision_applied', ['planning_escalation_id' => $escalation->id, 'revision_attempt_number' => $lockedAttempt->number, 'reason' => $proposal['reason'], 'changed_fields' => array_keys($changed->all()), 'contract_baseline_reset' => true], $task->project, $task);
            $this->audit->record('task.transitioned', ['from' => TaskStatus::Blocked->value, 'to' => TaskStatus::ChangesRequired->value], $task->project, $task);
        }, attempts: 3);
    }

    /** @param array<string,mixed> $replacements */
    private function validateReplacements(Task $task, array $replacements): void
    {
        foreach (['acceptance_criteria', 'scope', 'constraints', 'relevant_paths', 'verification_commands'] as $field) {
            if (array_key_exists($field, $replacements) && (! is_array($replacements[$field]) || collect($replacements[$field])->contains(fn (mixed $value): bool => ! is_string($value) || trim($value) === ''))) {
                throw ValidationException::withMessages([$field => 'Must be a bounded list of non-empty strings.']);
            }
        }
        if (isset($replacements['implementation_prompt']) && (! is_string($replacements['implementation_prompt']) || trim($replacements['implementation_prompt']) === '')) {
            throw ValidationException::withMessages(['implementation_prompt' => 'Must be non-empty text.']);
        }
        if (isset($replacements['verification_commands']) && ! $this->validator->verificationCommandsAreSafe($replacements['verification_commands'])) {
            throw ValidationException::withMessages(['verification_commands' => 'Contains an unsafe command.']);
        }
        if (isset($replacements['relevant_paths']) && collect($replacements['relevant_paths'])->contains(fn (string $path): bool => str_starts_with($path, '/') || str_contains($path, '\\') || in_array('..', explode('/', $path), true))) {
            throw ValidationException::withMessages(['relevant_paths' => 'Contains a path outside the project.']);
        }
        if (isset($replacements['dependencies'])) {
            if (! is_array($replacements['dependencies']) || collect($replacements['dependencies'])->contains(fn (mixed $key): bool => ! is_string($key))) {
                throw ValidationException::withMessages(['dependencies' => 'Must contain task keys.']);
            }
            $taskPhase = $task->phase()->first();
            $dependencies = Task::query()->where('project_id', $task->project_id)->whereIn('key', $replacements['dependencies'])->with('phase')->get();
            if ($dependencies->count() !== count(array_unique($replacements['dependencies'])) || $dependencies->contains(fn (Task $dependency): bool => $dependency->id === $task->id
                || $dependency->position >= $task->position
                || ($taskPhase !== null && $dependency->phase !== null && $dependency->phase->position > $taskPhase->position))) {
                throw ValidationException::withMessages(['dependencies' => 'Dependencies must be earlier Tasks and cannot belong to a later phase.']);
            }
        }
    }

    private function fail(TaskPlanningRevisionAttempt $attempt, string $reason): void
    {
        DB::transaction(function () use ($attempt, $reason): void {
            $locked = TaskPlanningRevisionAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $escalation = TaskPlanningEscalation::query()->lockForUpdate()->findOrFail($locked->task_planning_escalation_id);
            $task = Task::query()->lockForUpdate()->findOrFail($escalation->task_id);
            if (in_array($locked->status, ['applied', 'failed'], true)) {
                return;
            }
            $locked->update(['status' => 'failed', 'finished_at' => now()]);
            $count = $escalation->revisionAttempts()->count();
            $limit = max(1, (int) config('aios.max_task_planning_revisions'));
            if ($count >= $limit) {
                $escalation->update(['status' => 'blocked']);
                $this->audit->record('task.planning_revision_exhausted', ['planning_escalation_id' => $escalation->id, 'reason' => $reason, 'limit' => $limit], $task->project, $task);

                return;
            }
            $escalation->update(['status' => 'pending']);
            TaskPlanningRevisionAttempt::create(['task_planning_escalation_id' => $escalation->id, 'number' => $count + 1, 'status' => 'queued', 'claimed_at' => now()]);
            $this->audit->record('task.planning_revision_retry_scheduled', ['planning_escalation_id' => $escalation->id, 'reason' => $reason, 'attempt_number' => $locked->number], $task->project, $task);
        }, attempts: 3);
    }

    /** @return array{exit_code:int,output:string,error_output:string} */
    private function emptyExecution(): array
    {
        return ['exit_code' => 0, 'output' => '', 'error_output' => ''];
    }
}
