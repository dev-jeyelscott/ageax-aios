<?php

namespace App\Actions;

use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Skill;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class DecideKnowledgeImprovementCandidate
{
    private const int MaxProposedChangeCharacters = 1200;

    private const int MaxSkillInstructionCharacters = 20000;

    public function __construct(private AuditLogger $audit) {}

    public function handle(
        KnowledgeImprovementCandidate $candidate,
        User $operator,
        KnowledgeImprovementCandidateStatus $decision,
    ): KnowledgeImprovementCandidate {
        if ($decision === KnowledgeImprovementCandidateStatus::Pending) {
            throw new LogicException('Pending is not an operator decision.');
        }

        return DB::transaction(function () use ($candidate, $operator, $decision): KnowledgeImprovementCandidate {
            $locked = KnowledgeImprovementCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();
            $current = KnowledgeImprovementCandidateStatus::from($locked->getRawOriginal('status'));

            if ($current !== KnowledgeImprovementCandidateStatus::Pending) {
                return $locked;
            }

            $appliedSkillVersion = $locked->applied_skill_version;
            $appliedAt = $locked->applied_at;

            if ($decision === KnowledgeImprovementCandidateStatus::Approved
                && KnowledgeImprovementTarget::from($locked->getRawOriginal('target_type')) === KnowledgeImprovementTarget::Skill) {
                [$appliedSkillVersion, $appliedAt] = $this->applyApprovedSkillGuidance($locked);
            }

            $locked->update([
                'status' => $decision,
                'decided_by_user_id' => $operator->id,
                'decided_at' => now(),
                'reopen_after_occurrence' => $locked->occurrence_count + $this->reopenThreshold(),
                'applied_skill_version' => $appliedSkillVersion,
                'applied_at' => $appliedAt,
            ]);

            $this->audit->record('knowledge_improvement_candidate.decided', [
                'candidate_id' => $locked->id,
                'fingerprint' => $locked->fingerprint,
                'decision' => $decision->value,
                'target_type' => $locked->getRawOriginal('target_type'),
                'target_skill_id' => $locked->target_skill_id,
                'occurrence_count' => $locked->occurrence_count,
                'reopen_after_occurrence' => $locked->reopen_after_occurrence,
                'applied_skill_version' => $appliedSkillVersion,
                'repository_change_required' => $decision === KnowledgeImprovementCandidateStatus::Approved
                    && KnowledgeImprovementTarget::from($locked->getRawOriginal('target_type')) !== KnowledgeImprovementTarget::Skill,
            ], $locked->project);

            return $locked->refresh();
        }, attempts: 3);
    }

    /** @return array{0: ?int, 1: mixed} */
    private function applyApprovedSkillGuidance(KnowledgeImprovementCandidate $candidate): array
    {
        if ($candidate->target_skill_id === null) {
            throw new LogicException('The approved candidate no longer has an existing target Skill.');
        }

        $skill = Skill::query()
            ->whereKey($candidate->target_skill_id)
            ->where('project_id', $candidate->project_id)
            ->lockForUpdate()
            ->first();

        if ($skill === null) {
            throw new LogicException('The approved candidate target Skill no longer exists in this project.');
        }

        $change = Str::squish($candidate->proposed_change);

        if ($change === '') {
            throw new LogicException('The approved candidate has no bounded Skill guidance to apply.');
        }

        if (Str::length($change) > self::MaxProposedChangeCharacters) {
            throw new LogicException('The approved candidate guidance exceeds the bounded Skill update limit.');
        }

        $instructions = rtrim($skill->instructions);

        if (Str::contains($instructions, $change)) {
            return [$skill->version, now()];
        }

        $updatedInstructions = $instructions."\n\n".$change;

        if (Str::length($updatedInstructions) > self::MaxSkillInstructionCharacters) {
            throw new LogicException('Applying the approved guidance would exceed the Skill instruction limit.');
        }

        $previousVersion = $skill->version;
        $skill->update(['instructions' => $updatedInstructions]);
        $skill->refresh();

        $this->audit->record('skill.updated', [
            'project_id' => $skill->project_id,
            'skill_id' => $skill->id,
            'previous_version' => $previousVersion,
            'version' => $skill->version,
            'source' => 'knowledge_improvement_candidate',
            'knowledge_improvement_candidate_id' => $candidate->id,
        ], $candidate->project);

        return [$skill->version, now()];
    }

    private function reopenThreshold(): int
    {
        return max(3, (int) config('aios.knowledge_improvement_reopen_threshold', 3));
    }
}
