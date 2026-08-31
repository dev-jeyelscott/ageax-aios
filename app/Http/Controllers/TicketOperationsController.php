<?php

namespace App\Http\Controllers;

use App\Models\Phase;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketEscalationDecision;
use App\Models\TicketTriageAttempt;
use App\TicketDecision;
use App\TicketEscalationReason;
use App\TicketOperationalView;
use App\TicketOperatorAction;
use App\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TicketOperationsController extends Controller
{
    private const int RecentWindowDays = 7;

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'view' => ['nullable', Rule::enum(TicketOperationalView::class)],
        ]);
        $view = isset($validated['view'])
            ? TicketOperationalView::from((string) $validated['view'])
            : TicketOperationalView::NeedsOperatorDecision;
        $recentCutoff = now()->subDays(self::RecentWindowDays);

        $tickets = $this->queryForView($view, $recentCutoff)
            ->with([
                'project:id,name',
                'latestTriageAttempt',
            ])
            ->latest('updated_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Ticket $ticket): array => $this->ticketSummary($ticket))
            ->values()
            ->all();

        $views = [];

        foreach (TicketOperationalView::cases() as $operationalView) {
            $views[] = [
                'value' => $operationalView->value,
                'label' => $operationalView->label(),
                'description' => $operationalView->description(),
                'count' => $this->queryForView(
                    $operationalView,
                    $recentCutoff,
                )->count(),
                'href' => route('ticket-operations.index', [
                    'view' => $operationalView->value,
                ]),
            ];
        }

        return Inertia::render('ticket-operations/index', [
            'active_view' => $view->value,
            'recent_window_days' => self::RecentWindowDays,
            'views' => $views,
            'tickets' => $tickets,
        ]);
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load([
            'project:id,name,path',
            'convertedTask',
            'latestTriageAttempt.agentRun',
        ]);

        $attempt = $this->latestTriageAttempt($ticket);
        $structuredDecision = $this->structuredDecision($attempt);
        $validation = is_array($structuredDecision['aios_validation'] ?? null)
            ? $structuredDecision['aios_validation']
            : [];
        $escalationReasons = $this->stringList(
            $validation['escalation_reasons'] ?? [],
        );
        $criticalRoadmapInterruption = $this->criticalRoadmapInterruption(
            $escalationReasons,
        );
        $operatorDecision = $attempt === null
            ? null
            : TicketEscalationDecision::query()
                ->with('decidedBy:id,name')
                ->where('ticket_triage_attempt_id', $attempt->id)
                ->first();
        $activeEscalation = $this->rawString($ticket, 'status') === TicketStatus::Escalated->value
            && ($validation['requires_operator_decision'] ?? false) === true
            && $attempt !== null
            && $operatorDecision === null;
        $convertedTask = $this->convertedTask($ticket);

        return Inertia::render('ticket-operations/show', [
            'ticket' => [
                'id' => $ticket->id,
                'key' => $ticket->key,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'status' => $this->rawString($ticket, 'status'),
                'category' => $this->rawString($ticket, 'category'),
                'decision' => $this->rawString($ticket, 'decision'),
                'requester_urgency' => $this->rawString($ticket, 'requester_urgency'),
                'ai_suggested_priority' => $this->rawString($ticket, 'ai_suggested_priority'),
                'final_priority' => $this->rawString($ticket, 'final_priority'),
                'triage_confidence' => $ticket->triage_confidence,
                'awaiting_response_until' => $this->isoDateAttribute($ticket, 'awaiting_response_until'),
                'triaged_at' => $this->isoDateAttribute($ticket, 'triaged_at'),
                'closed_at' => $this->isoDateAttribute($ticket, 'closed_at'),
                'inactivity_closed_at' => $this->isoDateAttribute($ticket, 'inactivity_closed_at'),
                'created_at' => $this->isoDateAttribute($ticket, 'created_at'),
                'updated_at' => $this->isoDateAttribute($ticket, 'updated_at'),
                'project' => [
                    'id' => $ticket->project->id,
                    'name' => $ticket->project->name,
                ],
                'converted_task' => $convertedTask === null
                    ? null
                    : [
                        'id' => $convertedTask->id,
                        'key' => $convertedTask->key,
                        'title' => $convertedTask->title,
                        'status' => $this->rawString($convertedTask, 'status'),
                    ],
            ],
            'triage' => $attempt === null
                ? null
                : [
                    'attempt_id' => $attempt->id,
                    'number' => $attempt->number,
                    'status' => $attempt->status,
                    'agent_run_id' => $attempt->agent_run_id,
                    'agent_run_url' => $attempt->agent_run_id === null
                        ? null
                        : route('projects.agent-runs.show', [
                            $ticket->project,
                            $attempt->agent_run_id,
                        ]),
                    'finished_at' => $this->isoDateAttribute($attempt, 'finished_at'),
                    'classification' => [
                        'category' => $this->nullableString($structuredDecision['category'] ?? null),
                        'decision' => $this->nullableString($structuredDecision['decision'] ?? null),
                        'confidence' => $structuredDecision['confidence'] ?? null,
                        'complexity' => $this->nullableString($structuredDecision['complexity'] ?? null),
                        'suggested_priority' => $this->nullableString($structuredDecision['suggested_priority'] ?? null),
                        'implementation_required' => ($structuredDecision['implementation_required'] ?? null) === true,
                    ],
                    'summary' => $this->nullableString($structuredDecision['summary'] ?? null),
                    'decision_evidence' => $this->nullableString($structuredDecision['internal_reason_summary'] ?? null),
                    'documentation_alignment' => $this->stringList(
                        $structuredDecision['documentation_alignment'] ?? [],
                    ),
                    'escalation_flags' => $this->stringList(
                        $structuredDecision['escalation_flags'] ?? [],
                    ),
                    'aios_escalation_reasons' => $escalationReasons,
                    'requester_reply' => $this->nullableString(
                        $structuredDecision['requester_reply'] ?? null,
                    ),
                    'questions' => $this->stringList(
                        $structuredDecision['questions'] ?? [],
                    ),
                    'blockers' => $this->stringList(
                        $structuredDecision['blockers'] ?? [],
                    ),
                    'proposed_task' => is_array($structuredDecision['proposed_task'] ?? null)
                        ? $structuredDecision['proposed_task']
                        : null,
                    'proposed_tasks' => is_array($structuredDecision['proposed_tasks'] ?? null)
                        ? $structuredDecision['proposed_tasks']
                        : null,
                    'phase_placement_consequence' => $this->phasePlacementConsequence(
                        $ticket,
                        $structuredDecision,
                        $escalationReasons,
                    ),
                    'critical_roadmap_interruption' => $criticalRoadmapInterruption,
                ],
            'operator_decision' => $operatorDecision === null
                ? null
                : [
                    'id' => $operatorDecision->id,
                    'action' => $this->rawString($operatorDecision, 'action'),
                    'direction' => $operatorDecision->direction,
                    'decided_by' => $operatorDecision->decidedBy?->name,
                    'created_at' => $this->isoDateAttribute($operatorDecision, 'created_at'),
                ],
            'operator_action' => [
                'active' => $activeEscalation,
                'url' => $activeEscalation
                    ? route('projects.tickets.escalation-decisions.store', [
                        $ticket->project,
                        $ticket,
                        $attempt,
                    ])
                    : null,
                'approval_action' => $criticalRoadmapInterruption
                    ? TicketOperatorAction::ApproveCriticalRoadmapInterruption->value
                    : TicketOperatorAction::ApproveProposedHandling->value,
                'approval_label' => $criticalRoadmapInterruption
                    ? 'Approve roadmap interruption'
                    : 'Approve proposed handling',
                'reject_action' => TicketOperatorAction::Reject->value,
                'request_information_action' => TicketOperatorAction::RequestRequesterInformation->value,
                'provide_direction_action' => TicketOperatorAction::ProvideDirection->value,
            ],
            'links' => [
                'operations_index' => route('ticket-operations.index'),
                'ticket_detail' => route('projects.tickets.show', [
                    $ticket->project,
                    $ticket,
                ]),
                'project' => route('projects.show', $ticket->project),
                'converted_task' => $convertedTask === null
                    ? null
                    : route('projects.tasks.show', [
                        $ticket->project,
                        $convertedTask,
                    ]),
            ],
        ]);
    }

    /** @return Builder<Ticket> */
    private function queryForView(
        TicketOperationalView $view,
        CarbonInterface $recentCutoff,
    ): Builder {
        $query = Ticket::query();

        return match ($view) {
            TicketOperationalView::NeedsOperatorDecision => $query
                ->where('status', TicketStatus::Escalated->value)
                ->whereHas(
                    'latestTriageAttempt',
                    fn (Builder $attemptQuery): Builder => $attemptQuery
                        ->where('status', 'completed')
                        ->where(
                            'structured_decision->aios_validation->requires_operator_decision',
                            true,
                        ),
                ),
            TicketOperationalView::AwaitingRequester => $query
                ->where('status', TicketStatus::AwaitingRequester->value),
            TicketOperationalView::RecentlyAutoConverted => $query
                ->where('status', TicketStatus::Converted->value)
                ->whereNotNull('converted_task_id')
                ->where('triaged_at', '>=', $recentCutoff),
            TicketOperationalView::BlockedOrFailedTriage => $query
                ->where(function (Builder $ticketQuery): void {
                    $ticketQuery
                        ->where('status', TicketStatus::Failed->value)
                        ->orWhere(function (Builder $blockedQuery): void {
                            $blockedQuery
                                ->where('status', TicketStatus::Triaging->value)
                                ->whereHas(
                                    'latestTriageAttempt',
                                    fn (Builder $attemptQuery): Builder => $attemptQuery
                                        ->where('status', 'completed')
                                        ->where(
                                            'structured_decision->decision',
                                            TicketDecision::Blocked->value,
                                        ),
                                );
                        });
                }),
            TicketOperationalView::RecentlyAutoClosed => $query
                ->where('status', TicketStatus::Closed->value)
                ->whereNotNull('inactivity_closed_at')
                ->where('inactivity_closed_at', '>=', $recentCutoff),
        };
    }

    /** @return array<string, mixed> */
    private function ticketSummary(Ticket $ticket): array
    {
        $attempt = $this->latestTriageAttempt($ticket);
        $structuredDecision = $this->structuredDecision($attempt);
        $validation = is_array($structuredDecision['aios_validation'] ?? null)
            ? $structuredDecision['aios_validation']
            : [];

        return [
            'id' => $ticket->id,
            'key' => $ticket->key,
            'title' => $ticket->title,
            'status' => $this->rawString($ticket, 'status'),
            'category' => $this->rawString($ticket, 'category')
                ?? $this->nullableString($structuredDecision['category'] ?? null),
            'decision' => $this->rawString($ticket, 'decision')
                ?? $this->nullableString($structuredDecision['decision'] ?? null),
            'final_priority' => $this->rawString($ticket, 'final_priority'),
            'ai_suggested_priority' => $this->rawString($ticket, 'ai_suggested_priority')
                ?? $this->nullableString($structuredDecision['suggested_priority'] ?? null),
            'project' => [
                'id' => $ticket->project->id,
                'name' => $ticket->project->name,
            ],
            'latest_triage' => $attempt === null
                ? null
                : [
                    'number' => $attempt->number,
                    'status' => $attempt->status,
                    'agent_run_id' => $attempt->agent_run_id,
                    'summary' => $this->nullableString($structuredDecision['summary'] ?? null),
                    'escalation_reasons' => $this->stringList(
                        $validation['escalation_reasons'] ?? [],
                    ),
                ],
            'awaiting_response_until' => $this->isoDateAttribute($ticket, 'awaiting_response_until'),
            'triaged_at' => $this->isoDateAttribute($ticket, 'triaged_at'),
            'inactivity_closed_at' => $this->isoDateAttribute($ticket, 'inactivity_closed_at'),
            'updated_at' => $this->isoDateAttribute($ticket, 'updated_at'),
            'operations_url' => route('ticket-operations.show', $ticket),
        ];
    }

    private function latestTriageAttempt(Ticket $ticket): ?TicketTriageAttempt
    {
        $attempt = $ticket->getRelation('latestTriageAttempt');

        return $attempt instanceof TicketTriageAttempt ? $attempt : null;
    }

    private function convertedTask(Ticket $ticket): ?Task
    {
        $task = $ticket->getRelation('convertedTask');

        return $task instanceof Task ? $task : null;
    }

    /** @return array<string, mixed> */
    private function structuredDecision(?TicketTriageAttempt $attempt): array
    {
        if ($attempt === null) {
            return [];
        }

        $decision = $attempt->getAttribute('structured_decision');

        return is_array($decision) ? $decision : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function rawString(Model $model, string $attribute): ?string
    {
        $value = $model->getRawOriginal($attribute);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isoDateAttribute(Model $model, string $attribute): ?string
    {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface ? $value->toISOString() : null;
    }

    /** @param list<string> $reasons */
    private function criticalRoadmapInterruption(array $reasons): bool
    {
        return in_array(
            TicketEscalationReason::RoadmapOrPhaseReorderingOrInterruptionRequested->value,
            $reasons,
            true,
        ) || in_array(
            TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value,
            $reasons,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $structuredDecision
     * @param  list<string>  $reasons
     */
    private function phasePlacementConsequence(
        Ticket $ticket,
        array $structuredDecision,
        array $reasons,
    ): string {
        if (($structuredDecision['implementation_required'] ?? false) !== true) {
            return 'No implementation Task is proposed, so no phase placement mutation is permitted.';
        }

        if ($this->criticalRoadmapInterruption($reasons)) {
            return 'The proposal could interrupt or reorder roadmap work. No ordering change is permitted until explicit operator approval is recorded and a fresh PM triage attempt re-evaluates current durable state.';
        }

        $proposal = $structuredDecision['proposed_task'] ?? null;

        if (! is_array($proposal)) {
            return 'Implementation was requested, but no bounded Task proposal is available for deterministic placement.';
        }

        $preferredPhaseId = $proposal['preferred_phase_id'] ?? null;

        if (is_int($preferredPhaseId)) {
            $preferredPhase = Phase::query()
                ->whereKey($preferredPhaseId)
                ->where('project_id', $ticket->project_id)
                ->first();

            if ($preferredPhase === null) {
                return 'The proposed preferred phase no longer resolves inside this project; placement requires fresh AIOS validation.';
            }

            return "The PM proposed phase #{$preferredPhase->position} ({$preferredPhase->title}). AIOS must still re-check review start and dependencies before any Task placement.";
        }

        return 'No preferred phase is locked. AIOS will re-check current phase/review-start eligibility and otherwise place safe work in the existing future intake/backlog flow.';
    }
}
