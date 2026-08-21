<?php

use App\TaskStatus;

function taskStatusSemanticsMatrix(): array
{
    return [
        'queued' => [TaskStatus::Queued, false, true, false, false, false, true, false],
        'coding' => [TaskStatus::Coding, false, false, false, false, false, true, false],
        'validating' => [TaskStatus::Validating, false, false, false, false, false, true, false],
        'ready for review' => [TaskStatus::ReadyForReview, false, false, true, true, false, true, true],
        'reviewing' => [TaskStatus::Reviewing, false, false, false, false, false, true, false],
        'changes required' => [TaskStatus::ChangesRequired, false, true, false, false, false, true, false],
        'done' => [TaskStatus::Done, true, false, false, true, true, false, true],
        'blocked' => [TaskStatus::Blocked, false, false, false, false, false, true, false],
        'interrupted' => [TaskStatus::Interrupted, false, false, false, false, false, true, false],
        'failed' => [TaskStatus::Failed, false, true, false, false, false, true, false],
        'cancelled' => [TaskStatus::Cancelled, true, false, false, true, true, false, true],
    ];
}

test('every task status has an explicit workflow semantics row', function () {
    $matrixStatuses = array_map(
        fn (array $row): string => $row[0]->value,
        taskStatusSemanticsMatrix(),
    );
    $enumStatuses = array_map(
        fn (TaskStatus $status): string => $status->value,
        TaskStatus::cases(),
    );

    expect(array_values($matrixStatuses))->toBe($enumStatuses);
});

test('task status workflow semantics stay deterministic', function (
    TaskStatus $status,
    bool $terminal,
    bool $coderClaimable,
    bool $reviewerClaimable,
    bool $samePhaseDependencySatisfied,
    bool $crossPhaseDependencySatisfied,
    bool $blocksPhaseCompletion,
    bool $reviewBarrierSatisfied,
) {
    expect($status->isTerminal())->toBe($terminal)
        ->and($status->isCoderClaimable())->toBe($coderClaimable)
        ->and($status->isReviewerClaimable())->toBe($reviewerClaimable)
        ->and($status->satisfiesDependency(true))->toBe($samePhaseDependencySatisfied)
        ->and($status->satisfiesDependency(false))->toBe($crossPhaseDependencySatisfied)
        ->and($status->blocksPhaseCompletion())->toBe($blocksPhaseCompletion)
        ->and($status->satisfiesPhaseReviewBarrier())->toBe($reviewBarrierSatisfied);
})->with(taskStatusSemanticsMatrix());

test('claim and phase query values are derived from the semantics matrix', function () {
    expect(TaskStatus::coderClaimableValues())->toBe([
        TaskStatus::Queued->value,
        TaskStatus::ChangesRequired->value,
        TaskStatus::Failed->value,
    ])->and(TaskStatus::reviewerClaimableValues())->toBe([
        TaskStatus::ReadyForReview->value,
    ])->and(TaskStatus::phaseCompletionSatisfiedValues())->toBe([
        TaskStatus::Done->value,
        TaskStatus::Cancelled->value,
    ]);
});
