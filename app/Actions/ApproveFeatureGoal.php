<?php

namespace App\Actions;

use App\Models\GoalRun;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveFeatureGoal
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(GoalRun $goalRun, ?string $goalText = null): GoalRun
    {
        return DB::transaction(function () use ($goalRun, $goalText): GoalRun {
            $locked = GoalRun::query()->lockForUpdate()->findOrFail($goalRun->id);
            if (! in_array($locked->status, ['awaiting_approval', 'approved'], true)) {
                throw ValidationException::withMessages(['goal' => 'Only a planned goal may be approved.']);
            }
            if ($goalText !== null && trim($goalText) !== '' && $goalText !== $locked->goal_text) {
                $locked->update(['goal_text' => $goalText, 'version' => $locked->version + 1]);
                $this->audit->record('feature_goal.edited', ['goal_run_id' => $locked->id, 'version' => $locked->version], $locked->project, $locked->task);
            }
            $locked->update(['status' => 'approved', 'approved_at' => now()]);
            $this->audit->record('feature_goal.approved', ['goal_run_id' => $locked->id, 'version' => $locked->version], $locked->project, $locked->task);

            return $locked->refresh();
        }, attempts: 3);
    }
}
