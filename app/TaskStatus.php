<?php

namespace App;

enum TaskStatus: string
{
    case Queued = 'queued';
    case Coding = 'coding';
    case Validating = 'validating';
    case ReadyForReview = 'ready_for_review';
    case Reviewing = 'reviewing';
    case ChangesRequired = 'changes_required';
    case Done = 'done';
    case Blocked = 'blocked';
    case Interrupted = 'interrupted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this->semantics()['terminal'];
    }

    public function isCoderClaimable(): bool
    {
        return $this->semantics()['coder_claimable'];
    }

    public function isReviewerClaimable(): bool
    {
        return $this->semantics()['reviewer_claimable'];
    }

    public function satisfiesDependency(bool $samePhase): bool
    {
        return match ($this->semantics()['dependency_satisfaction']) {
            'always' => true,
            'same_phase' => $samePhase,
            'never' => false,
        };
    }

    public function blocksPhaseCompletion(): bool
    {
        return $this->semantics()['blocks_phase_completion'];
    }

    /** @return list<string> */
    public static function coderClaimableValues(): array
    {
        return self::valuesMatching(
            fn (self $status): bool => $status->isCoderClaimable(),
        );
    }

    /** @return list<string> */
    public static function reviewerClaimableValues(): array
    {
        return self::valuesMatching(
            fn (self $status): bool => $status->isReviewerClaimable(),
        );
    }

    /** @return list<string> */
    public static function phaseCompletionSatisfiedValues(): array
    {
        return self::valuesMatching(
            fn (self $status): bool => ! $status->blocksPhaseCompletion(),
        );
    }

    /**
     * @return array{
     *     terminal: bool,
     *     coder_claimable: bool,
     *     reviewer_claimable: bool,
     *     dependency_satisfaction: 'always'|'same_phase'|'never',
     *     blocks_phase_completion: bool
     * }
     */
    private function semantics(): array
    {
        return match ($this) {
            self::Queued => [
                'terminal' => false,
                'coder_claimable' => true,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'never',
                'blocks_phase_completion' => true,
            ],
            self::Coding => [
                'terminal' => false,
                'coder_claimable' => false,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'never',
                'blocks_phase_completion' => true,
            ],
            self::Validating => [
                'terminal' => false,
                'coder_claimable' => false,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'never',
                'blocks_phase_completion' => true,
            ],
            self::ReadyForReview => [
                'terminal' => false,
                'coder_claimable' => false,
                'reviewer_claimable' => true,
                'dependency_satisfaction' => 'same_phase',
                'blocks_phase_completion' => true,
            ],
            self::Reviewing => [
                'terminal' => false,
                'coder_claimable' => false,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'same_phase',
                'blocks_phase_completion' => true,
            ],
            self::ChangesRequired => [
                'terminal' => false,
                'coder_claimable' => true,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'never',
                'blocks_phase_completion' => true,
            ],
            self::Done => [
                'terminal' => true,
                'coder_claimable' => false,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'always',
                'blocks_phase_completion' => false,
            ],
            self::Blocked => [
                'terminal' => false,
                'coder_claimable' => false,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'never',
                'blocks_phase_completion' => true,
            ],
            self::Interrupted => [
                'terminal' => false,
                'coder_claimable' => false,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'never',
                'blocks_phase_completion' => true,
            ],
            self::Failed => [
                'terminal' => false,
                'coder_claimable' => true,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'never',
                'blocks_phase_completion' => true,
            ],
            self::Cancelled => [
                'terminal' => true,
                'coder_claimable' => false,
                'reviewer_claimable' => false,
                'dependency_satisfaction' => 'always',
                'blocks_phase_completion' => false,
            ],
        };
    }

    /**
     * @param  callable(self): bool  $predicate
     * @return list<string>
     */
    private static function valuesMatching(callable $predicate): array
    {
        $values = [];

        foreach (self::cases() as $status) {
            if ($predicate($status)) {
                $values[] = $status->value;
            }
        }

        return $values;
    }
}
