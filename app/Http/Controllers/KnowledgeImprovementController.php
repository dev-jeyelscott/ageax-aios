<?php

namespace App\Http\Controllers;

use App\Actions\DecideKnowledgeImprovementCandidate;
use App\Actions\PromoteGlobalKnowledgePattern;
use App\Http\Requests\DecideKnowledgeImprovementCandidateRequest;
use App\Http\Requests\PromoteGlobalKnowledgePatternRequest;
use App\KnowledgeImprovementCandidateStatus;
use App\Models\GlobalKnowledgePattern;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class KnowledgeImprovementController extends Controller
{
    /**
     * Render the project-scoped knowledge queue with current explicit global-promotion state.
     */
    public function index(Project $project): Response
    {
        $candidates = $project->knowledgeImprovementCandidates()
            ->with('targetSkill:id,project_id,name,slug,version,enabled')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        $candidateIds = $candidates->pluck('id')->all();

        $promotedPatterns = $candidateIds === []
            ? collect()
            : GlobalKnowledgePattern::query()
                ->whereIn('source_candidate_id', $candidateIds)
                ->get()
                ->keyBy(
                    fn (GlobalKnowledgePattern $pattern): string => $this->promotionKey(
                        $pattern->source_candidate_id,
                        $pattern->source_evidence_hash,
                    ),
                );

        $candidatePayloads = [];

        foreach ($candidates as $candidate) {
            /** @var KnowledgeImprovementCandidate $candidate */
            $occurrenceCount = (int) $candidate->getAttribute('occurrence_count');
            $targetSkill = $candidate->getRelation('targetSkill');

            $promotedPattern = $promotedPatterns->get(
                $this->promotionKey(
                    $candidate->id,
                    (string) $candidate->evidence_hash,
                ),
            );

            $candidatePayloads[] = [
                'id' => $candidate->id,
                'fingerprint' => $candidate->fingerprint,
                'source_kind' => $candidate->source_kind,
                'failure_code' => $candidate->failure_code,
                'affected_role' => $candidate->affected_role,
                'affected_area' => $candidate->affected_area,
                'status' => (string) $candidate->getRawOriginal('status'),
                'target_type' => (string) $candidate->getRawOriginal('target_type'),
                'evidence_summary' => $candidate->evidence_summary,
                'proposed_change' => $candidate->proposed_change,
                'evidence' => $candidate->getAttribute('evidence'),
                'occurrence_count' => $occurrenceCount,
                'confidence' => $occurrenceCount >= 10
                    ? 'high'
                    : ($occurrenceCount >= 6 ? 'medium' : 'threshold_met'),
                'reopen_after_occurrence' => $candidate->reopen_after_occurrence,
                'first_seen_at' => $this->serializeDateAttribute(
                    $candidate,
                    'first_seen_at',
                ),
                'last_seen_at' => $this->serializeDateAttribute(
                    $candidate,
                    'last_seen_at',
                ),
                'decided_at' => $this->serializeDateAttribute(
                    $candidate,
                    'decided_at',
                ),
                'applied_at' => $this->serializeDateAttribute(
                    $candidate,
                    'applied_at',
                ),
                'applied_skill_version' => $candidate->applied_skill_version,
                'target_skill' => $targetSkill instanceof Skill ? [
                    'id' => $targetSkill->id,
                    'name' => $targetSkill->name,
                    'slug' => $targetSkill->slug,
                    'version' => $targetSkill->version,
                    'enabled' => (bool) $targetSkill->enabled,
                ] : null,
                'global_pattern' => $promotedPattern instanceof GlobalKnowledgePattern
                        ? [
                            'id' => $promotedPattern->id,
                            'name' => $promotedPattern->name,
                            'category' => $promotedPattern->category,
                            'version' => $promotedPattern->version,
                            'enabled' => $promotedPattern->enabled,
                            'superseded_at' => $this->serializeDateAttribute(
                                $promotedPattern,
                                'superseded_at',
                            ),
                        ]
                        : null,
            ];
        }

        return Inertia::render('projects/knowledge-improvements/index', [
            'project' => $project->only(['id', 'name', 'path']),
            'candidates' => $candidatePayloads,
            'patternCategories' => GlobalKnowledgePattern::allowedCategories(),
            'patternRoles' => GlobalKnowledgePattern::allowedRoles(),
        ]);
    }

    /**
     * Persist the existing project-level operator decision.
     */
    public function decide(
        DecideKnowledgeImprovementCandidateRequest $request,
        Project $project,
        KnowledgeImprovementCandidate $candidate,
        DecideKnowledgeImprovementCandidate $decide,
    ): RedirectResponse {
        abort_unless($candidate->project_id === $project->id, 404);

        /** @var User $user */
        $user = $request->user();

        try {
            $decide->handle(
                $candidate,
                $user,
                KnowledgeImprovementCandidateStatus::from(
                    $request->string('decision')->toString(),
                ),
            );
        } catch (LogicException $exception) {
            return back()->withErrors([
                'decision' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Explicitly promote the current approved candidate evidence into global reusable guidance.
     */
    public function promote(
        PromoteGlobalKnowledgePatternRequest $request,
        Project $project,
        KnowledgeImprovementCandidate $candidate,
        PromoteGlobalKnowledgePattern $promote,
    ): RedirectResponse {
        abort_unless($candidate->project_id === $project->id, 404);

        /** @var User $user */
        $user = $request->user();

        try {
            $promote->handle(
                $candidate,
                $user,
                $request->validated(),
            );
        } catch (LogicException $exception) {
            return back()->withErrors([
                'promotion' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Serialize one Eloquent date attribute for the Inertia boundary.
     */
    private function serializeDateAttribute(
        Model $model,
        string $attribute,
    ): ?string {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface
            ? $value->toIso8601String()
            : null;
    }

    /**
     * Build the lookup key for an exact candidate/evidence global promotion.
     */
    private function promotionKey(
        int $candidateId,
        string $evidenceHash,
    ): string {
        return $candidateId.':'.$evidenceHash;
    }
}
