<?php

namespace App\Http\Controllers;

use App\Actions\DecideKnowledgeImprovementCandidate;
use App\Http\Requests\DecideKnowledgeImprovementCandidateRequest;
use App\KnowledgeImprovementCandidateStatus;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class KnowledgeImprovementController extends Controller
{
    public function index(Project $project): Response
    {
        $candidates = $project->knowledgeImprovementCandidates()
            ->with('targetSkill:id,project_id,name,slug,version,enabled')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        return Inertia::render('projects/knowledge-improvements/index', [
            'project' => $project->only(['id', 'name', 'path']),
            'candidates' => $candidates
                ->map(fn (KnowledgeImprovementCandidate $candidate): array => [
                    'id' => $candidate->id,
                    'fingerprint' => $candidate->fingerprint,
                    'source_kind' => $candidate->source_kind,
                    'failure_code' => $candidate->failure_code,
                    'affected_role' => $candidate->affected_role,
                    'affected_area' => $candidate->affected_area,
                    'status' => $candidate->status->value,
                    'target_type' => $candidate->target_type->value,
                    'evidence_summary' => $candidate->evidence_summary,
                    'proposed_change' => $candidate->proposed_change,
                    'evidence' => $candidate->evidence,
                    'occurrence_count' => $candidate->occurrence_count,
                    'confidence' => $candidate->occurrence_count >= 10 ? 'high' : ($candidate->occurrence_count >= 6 ? 'medium' : 'threshold_met'),
                    'reopen_after_occurrence' => $candidate->reopen_after_occurrence,
                    'first_seen_at' => $candidate->first_seen_at->toIso8601String(),
                    'last_seen_at' => $candidate->last_seen_at->toIso8601String(),
                    'decided_at' => $candidate->decided_at?->toIso8601String(),
                    'applied_at' => $candidate->applied_at?->toIso8601String(),
                    'applied_skill_version' => $candidate->applied_skill_version,
                    'target_skill' => $candidate->targetSkill === null ? null : [
                        'id' => $candidate->targetSkill->id,
                        'name' => $candidate->targetSkill->name,
                        'slug' => $candidate->targetSkill->slug,
                        'version' => $candidate->targetSkill->version,
                        'enabled' => (bool) $candidate->targetSkill->enabled,
                    ],
                ])
                ->values(),
        ]);
    }

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
                KnowledgeImprovementCandidateStatus::from($request->string('decision')->toString()),
            );
        } catch (LogicException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return back();
    }
}
