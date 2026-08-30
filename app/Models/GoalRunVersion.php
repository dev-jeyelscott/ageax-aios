<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['goal_run_id', 'version', 'goal_text', 'source', 'created_by_user_id'])]
class GoalRunVersion extends Model
{
    /** @return BelongsTo<GoalRun, $this> */
    public function goalRun(): BelongsTo
    {
        return $this->belongsTo(GoalRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
