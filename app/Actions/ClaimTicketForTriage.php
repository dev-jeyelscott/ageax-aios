<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\ProjectStatus;
use App\Services\AuditLogger;
use App\Services\TicketWorkflow;
use App\TicketStatus;
use Illuminate\Support\Facades\DB;

class ClaimTicketForTriage
{
    public function __construct(
        private TicketWorkflow $workflow,
        private AuditLogger $audit,
    ) {}

    public function handle(Project $project): ?TicketTriageAttempt
    {
        return DB::transaction(function () use ($project): ?TicketTriageAttempt {
            $lockedProject = Project::query()
                ->lockForUpdate()
                ->findOrFail($project->id);

            if (
                ProjectStatus::from($lockedProject->getRawOriginal('status')) !== ProjectStatus::Running
                || $this->hasRoadmapPrecedence($lockedProject)
                || $this->hasActiveTicketTriage($lockedProject)
            ) {
                return null;
            }

            $ticket = $this->nextEligibleTicket($lockedProject);

            if ($ticket === null) {
                return null;
            }

            $status = TicketStatus::from((string) $ticket->getRawOriginal('status'));

            if (! in_array($status, [TicketStatus::Failed, TicketStatus::Open], true)) {
                return null;
            }

            $number = ((int) $ticket->triageAttempts()->max('number')) + 1;

            $attempt = TicketTriageAttempt::create([
                'ticket_id' => $ticket->id,
                'agent_run_id' => null,
                'number' => $number,
                'status' => 'claimed',
                'structured_decision' => null,
                'claimed_at' => now(),
                'finished_at' => null,
            ]);

            $this->workflow->transition($ticket, TicketStatus::Triaging);

            $this->audit->record('ticket.claimed', [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->key,
                'ticket_triage_attempt_id' => $attempt->id,
                'attempt_number' => $attempt->number,
                'previous_status' => $status->value,
            ], $lockedProject);

            $attempt->load('ticket');

            return $attempt;
        }, attempts: 3);
    }

    private function hasRoadmapPrecedence(Project $project): bool
    {
        return $project->roadmaps()
            ->whereIn('status', [
                'uploaded',
                'failed',
                'in_progress',
                'processing',
            ])
            ->exists();
    }

    private function hasActiveTicketTriage(Project $project): bool
    {
        return $project->tickets()
            ->where('status', TicketStatus::Triaging->value)
            ->exists();
    }

    private function nextEligibleTicket(Project $project): ?Ticket
    {
        foreach ([TicketStatus::Failed, TicketStatus::Open] as $status) {
            $ticket = Ticket::query()
                ->whereBelongsTo($project)
                ->where('status', $status->value)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($ticket !== null) {
                return $ticket;
            }
        }

        return null;
    }
}
