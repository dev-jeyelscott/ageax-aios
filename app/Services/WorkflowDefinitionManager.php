<?php

namespace App\Services;

use App\Exceptions\InvalidWorkflowMutation;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\WorkflowDefinitionStatus;
use App\WorkflowStepKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkflowDefinitionManager
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Create the next immutable version for a workflow definition key with its declarative steps and transitions.
     *
     * @param  list<array{key: string, kind: WorkflowStepKind|string, label: string}>  $steps
     * @param  list<array{from: string, to: string}>  $transitions
     */
    public function createVersion(User $user, string $key, string $name, ?string $description, array $steps, array $transitions): WorkflowDefinition
    {
        try {
            Gate::forUser($user)->authorize('create', WorkflowDefinition::class);
        } catch (AuthorizationException $exception) {
            $this->recordRejected($user, 'workflow.create', $exception->getMessage(), ['key' => $key]);

            throw $exception;
        }

        try {
            return DB::transaction(function () use ($user, $key, $name, $description, $steps, $transitions): WorkflowDefinition {
                $previousVersion = (int) WorkflowDefinition::query()
                    ->where('key', $key)
                    ->max('version');
                $version = $previousVersion + 1;

                $definition = WorkflowDefinition::create([
                    'key' => $key,
                    'version' => $version,
                    'name' => $name,
                    'description' => $description,
                    'status' => WorkflowDefinitionStatus::Draft,
                    'created_by_user_id' => $user->id,
                ]);

                $stepsByKey = [];

                foreach (array_values($steps) as $position => $step) {
                    $kind = $step['kind'] instanceof WorkflowStepKind ? $step['kind'] : WorkflowStepKind::from($step['kind']);

                    $stepsByKey[$step['key']] = WorkflowStep::create([
                        'workflow_definition_id' => $definition->id,
                        'key' => $step['key'],
                        'position' => $position + 1,
                        'kind' => $kind,
                        'label' => $step['label'],
                    ]);
                }

                foreach ($transitions as $transition) {
                    $fromStep = $stepsByKey[$transition['from']] ?? null;
                    $toStep = $stepsByKey[$transition['to']] ?? null;

                    if ($fromStep === null || $toStep === null) {
                        throw new InvalidWorkflowMutation('A workflow transition must reference steps declared within the same workflow definition version.');
                    }

                    $definition->transitions()->create([
                        'from_step_id' => $fromStep->id,
                        'to_step_id' => $toStep->id,
                    ]);
                }

                $this->audit->record(
                    $previousVersion === 0 ? 'workflow.created' : 'workflow.version_created',
                    ['key' => $key, 'version' => $version, 'workflow_definition_id' => $definition->id],
                );

                return $definition->refresh();
            });
        } catch (InvalidWorkflowMutation $exception) {
            $this->recordRejected($user, 'workflow.create', $exception->getMessage(), ['key' => $key]);

            throw $exception;
        }
    }

    /**
     * Approve a draft workflow definition version.
     */
    public function approve(User $user, WorkflowDefinition $definition): WorkflowDefinition
    {
        try {
            Gate::forUser($user)->authorize('approve', $definition);
        } catch (AuthorizationException $exception) {
            $this->recordRejected($user, 'workflow.approve', $exception->getMessage(), ['workflow_definition_id' => $definition->id]);

            throw $exception;
        }

        $definition->update([
            'status' => WorkflowDefinitionStatus::Approved,
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
        ]);

        $this->audit->record('workflow.approved', [
            'workflow_definition_id' => $definition->id,
            'key' => $definition->key,
            'version' => $definition->version,
        ]);

        return $definition->refresh();
    }

    /**
     * Archive an approved workflow definition version.
     */
    public function archive(User $user, WorkflowDefinition $definition): WorkflowDefinition
    {
        try {
            Gate::forUser($user)->authorize('archive', $definition);
        } catch (AuthorizationException $exception) {
            $this->recordRejected($user, 'workflow.archive', $exception->getMessage(), ['workflow_definition_id' => $definition->id]);

            throw $exception;
        }

        $definition->update([
            'status' => WorkflowDefinitionStatus::Archived,
            'archived_at' => now(),
        ]);

        $this->audit->record('workflow.archived', [
            'workflow_definition_id' => $definition->id,
            'key' => $definition->key,
            'version' => $definition->version,
        ]);

        return $definition->refresh();
    }

    /**
     * Resolve the currently approved built-in default workflow definition, without installing it.
     *
     * Existing projects and Tasks keep their current behavior either way: this method never binds
     * a Task and never activates alternative execution routing, it only exposes the built-in
     * definition for callers that need to reference it.
     */
    public function resolveDefault(): ?WorkflowDefinition
    {
        return WorkflowDefinition::query()
            ->where('key', BuiltInWorkflow::Key)
            ->where('status', WorkflowDefinitionStatus::Approved)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Idempotently install the built-in default workflow as an approved immutable version.
     *
     * Reproduces the existing Coder-to-Validation-to-Reviewer Task lifecycle derived directly
     * from `TaskWorkflow::allowedTransitions()`, so this can never diverge from live execution
     * behavior. Repeated calls resolve the already-installed version rather than creating a
     * duplicate.
     */
    public function installBuiltIn(User $installer): WorkflowDefinition
    {
        $existing = $this->resolveDefault();

        if ($existing !== null) {
            return $existing;
        }

        $graph = BuiltInWorkflow::graph();
        $validation = app(WorkflowGraphValidator::class)->validate($graph);

        if (! $validation['valid']) {
            throw new InvalidWorkflowMutation(
                'The built-in workflow graph failed deterministic validation: '.json_encode($validation['errors'], JSON_THROW_ON_ERROR),
            );
        }

        $definition = $this->createVersion(
            $installer,
            BuiltInWorkflow::Key,
            BuiltInWorkflow::Name,
            'The backward-compatible built-in Coder-to-Validation-to-Reviewer Task lifecycle.',
            $graph['steps'],
            $graph['transitions'],
        );

        return $this->approve($installer, $definition);
    }

    /**
     * Bind the immutable workflow definition version selected for a Task at creation.
     *
     * The binding cannot be silently rewritten once persisted; a second call for a Task
     * that already carries a binding is rejected and audited rather than applied.
     */
    public function bindTaskVersion(Task $task, WorkflowDefinition $definition): Task
    {
        [$lockedTask, $rejected] = DB::transaction(function () use ($task, $definition): array {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);

            if ($lockedTask->getRawOriginal('workflow_definition_id') !== null) {
                return [$lockedTask, true];
            }

            $lockedTask->update(['workflow_definition_id' => $definition->id]);

            return [$lockedTask->refresh(), false];
        }, attempts: 3);

        if ($rejected) {
            $this->audit->record('workflow.task_binding_rejected', [
                'task_id' => $lockedTask->id,
                'existing_workflow_definition_id' => $lockedTask->getRawOriginal('workflow_definition_id'),
                'attempted_workflow_definition_id' => $definition->id,
            ], $lockedTask->project, $lockedTask);

            throw new InvalidWorkflowMutation('This Task already has an immutable workflow definition version bound and cannot be rewritten.');
        }

        $this->audit->record('workflow.task_version_bound', [
            'task_id' => $lockedTask->id,
            'workflow_definition_id' => $definition->id,
            'key' => $definition->key,
            'version' => $definition->version,
        ], $lockedTask->project, $lockedTask);

        return $lockedTask;
    }

    /**
     * Record a rejected workflow mutation attempt as durable audit evidence.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordRejected(User $user, string $action, string $reason, array $context): void
    {
        $this->audit->record('workflow.mutation_rejected', [
            'action' => $action,
            'user_id' => $user->id,
            'reason' => $reason,
            ...$context,
        ]);
    }
}
