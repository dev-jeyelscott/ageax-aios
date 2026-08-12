<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['status', 'prompt_hash', 'report', 'token_usage', 'log_path', 'live_output', 'exit_code', 'started_at', 'finished_at'])]
class VaultOrganizationRun extends Model
{
    protected $attributes = [
        'status' => 'running',
    ];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
