<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use App\TicketRequesterCategory;
use App\TicketUrgency;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreateTicket
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(
        Project $project,
        User $submitter,
        string $title,
        string $description,
        ?TicketRequesterCategory $requesterCategory = null,
        ?TicketUrgency $requesterUrgency = null,
    ): Ticket {
        return DB::transaction(function () use (
            $project,
            $submitter,
            $title,
            $description,
            $requesterCategory,
            $requesterUrgency,
        ): Ticket {
            $lockedProject = Project::query()
                ->lockForUpdate()
                ->findOrFail($project->id);

            $ticket = $lockedProject->tickets()->create([
                'submitted_by_user_id' => $submitter->id,
                'key' => $this->nextKey($lockedProject),
                'title' => $title,
                'description' => $description,
                'requester_category' => $requesterCategory,
                'requester_urgency' => $requesterUrgency,
            ]);

            $this->audit->record('ticket.created', [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->key,
            ], $lockedProject);

            return $ticket->refresh();
        }, attempts: 3);
    }

    private function nextKey(Project $project): string
    {
        $lastKey = Ticket::query()
            ->where('project_id', $project->id)
            ->orderByDesc('id')
            ->value('key');

        if ($lastKey === null) {
            return 'TICKET-001';
        }

        if (preg_match('/^TICKET-(\d+)$/', $lastKey, $matches) !== 1) {
            throw new LogicException("Unable to derive the next Ticket key from [{$lastKey}].");
        }

        $nextNumber = ((int) $matches[1]) + 1;

        return 'TICKET-'.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
