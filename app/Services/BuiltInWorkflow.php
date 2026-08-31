<?php

namespace App\Services;

use App\TaskStatus;
use App\WorkflowStepKind;

/**
 * The backward-compatible built-in workflow: a declarative graph representation of the existing
 * Coder-to-Validation-to-Reviewer Task lifecycle. Its transitions are derived directly from
 * `TaskWorkflow::allowedTransitions()`, the single authoritative live-execution state machine, so
 * this representation can never silently drift from actual Task behavior.
 */
class BuiltInWorkflow
{
    public const string Key = 'default-task-lifecycle';

    public const string Name = 'Default Task Lifecycle';

    /**
     * Build the declarative graph input consumed by `WorkflowGraphValidator` and
     * `WorkflowDefinitionManager::createVersion()`.
     *
     * @return array{
     *     entry: string,
     *     steps: list<array{key: string, kind: string, label: string}>,
     *     transitions: list<array{from: string, to: string}>,
     * }
     */
    public static function graph(): array
    {
        return [
            'entry' => WorkflowStepKind::Queued->value,
            'steps' => self::steps(),
            'transitions' => self::transitions(),
        ];
    }

    /**
     * @return list<array{key: string, kind: string, label: string}>
     */
    private static function steps(): array
    {
        $labels = [
            WorkflowStepKind::Queued->value => 'Queued',
            WorkflowStepKind::Coding->value => 'Coding',
            WorkflowStepKind::Validating->value => 'Validating',
            WorkflowStepKind::ReadyForReview->value => 'Ready for Review',
            WorkflowStepKind::Reviewing->value => 'Reviewing',
            WorkflowStepKind::ChangesRequired->value => 'Changes Required',
            WorkflowStepKind::Done->value => 'Done',
            WorkflowStepKind::Blocked->value => 'Blocked',
            WorkflowStepKind::Interrupted->value => 'Interrupted',
            WorkflowStepKind::Failed->value => 'Failed',
            WorkflowStepKind::Cancelled->value => 'Cancelled',
        ];

        return array_map(
            fn (WorkflowStepKind $kind): array => [
                'key' => $kind->value,
                'kind' => $kind->value,
                'label' => $labels[$kind->value],
            ],
            WorkflowStepKind::cases(),
        );
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    private static function transitions(): array
    {
        $transitions = [];

        foreach (TaskStatus::cases() as $from) {
            foreach (TaskWorkflow::allowedTransitions($from) as $to) {
                $transitions[] = ['from' => $from->value, 'to' => $to->value];
            }
        }

        return $transitions;
    }
}
