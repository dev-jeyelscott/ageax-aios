<?php

namespace App\Http\Controllers;

use App\Actions\DecideTicketEscalation;
use App\Http\Requests\DecideTicketEscalationRequest;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\Models\User;
use App\TicketOperatorAction;
use Illuminate\Http\RedirectResponse;

class TicketEscalationDecisionController extends Controller
{
    public function __invoke(
        DecideTicketEscalationRequest $request,
        Project $project,
        Ticket $ticket,
        TicketTriageAttempt $triageAttempt,
        DecideTicketEscalation $decide,
    ): RedirectResponse {
        abort_unless(
            $ticket->project_id === $project->id
                && $triageAttempt->ticket_id === $ticket->id,
            404,
        );

        /** @var User $operator */
        $operator = $request->user();
        $validated = $request->validated();
        $direction = is_string($validated['direction'] ?? null)
            ? $validated['direction']
            : null;

        $decide->handle(
            $ticket,
            $triageAttempt,
            $operator,
            TicketOperatorAction::from((string) $validated['action']),
            $direction,
        );

        return to_route(
            'projects.tickets.show',
            [$project, $ticket],
        );
    }
}
