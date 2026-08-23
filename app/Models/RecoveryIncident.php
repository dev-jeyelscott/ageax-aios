<?php

namespace App\Models;

use App\RecoveryIncidentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RecoveryIncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id', 'task_id', 'agent_worker_id', 'source_agent_run_id', 'failure_type', 'fingerprint',
    'source', 'exception_class', 'occurrence_count', 'first_seen_at', 'last_seen_at', 'status',
    'detected_at', 'evidence', 'root_cause', 'root_cause_category', 'recoverable', 'attempt_count',
    'fix_summary', 'validation_evidence', 'resulting_task_transition', 'escalation_reason',
    'base_sha', 'head_sha', 'commit_sha', 'changed_files', 'claim_token', 'claimed_at', 'resolved_at',
])]
/**
 * Durable incident summary for one AIOS workflow or runtime abnormality. Diagnosis and repair
 * attempts are recorded as AgentRuns linked via recovery_incident_id, and every meaningful
 * occurrence or lifecycle transition is recorded as an AuditEvent; this model holds only the
 * current mutable incident summary.
 *
 * @property RecoveryIncidentStatus $status
 * @property ?string $fingerprint
 * @property ?string $source
 * @property ?string $exception_class
 * @property int $occurrence_count
 * @property ?array<string, mixed> $evidence
 * @property ?bool $recoverable
 * @property ?array<string, mixed> $validation_evidence
 * @property ?array<int, string> $changed_files
 * @property CarbonImmutable $detected_at
 * @property ?CarbonImmutable $first_seen_at
 * @property ?CarbonImmutable $last_seen_at
 * @property ?CarbonImmutable $claimed_at
 * @property ?CarbonImmutable $resolved_at
 */
class RecoveryIncident extends Model
{
    /** @use HasFactory<RecoveryIncidentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RecoveryIncidentStatus::class,
            'occurrence_count' => 'integer',
            'evidence' => 'array',
            'recoverable' => 'boolean',
            'validation_evidence' => 'array',
            'changed_files' => 'array',
            'detected_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<AgentWorker, $this> */
    public function agentWorker(): BelongsTo
    {
        return $this->belongsTo(AgentWorker::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function sourceAgentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'source_agent_run_id');
    }

    /** @return HasMany<AgentRun, $this> */
    public function recoveryRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'recovery_incident_id');
    }

    public function isOpen(): bool
    {
        return RecoveryIncidentStatus::from($this->getRawOriginal('status'))->isOpen();
    }
}
