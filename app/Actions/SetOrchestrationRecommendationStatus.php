<?php

namespace App\Actions;

use App\Models\OrchestrationRecommendation;
use App\Models\User;
use App\OrchestrationRecommendationStatus;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

class SetOrchestrationRecommendationStatus
{
    /**
     * Create the Action with the durable AIOS audit boundary.
     */
    public function __construct(private AuditLogger $audit) {}

    /**
     * Persist one authorized terminal recommendation lifecycle decision without changing recommendation evidence.
     */
    public function handle(
        OrchestrationRecommendation $recommendation,
        User $operator,
        OrchestrationRecommendationStatus $status,
    ): OrchestrationRecommendation {
        if ($status === OrchestrationRecommendationStatus::Active) {
            throw new LogicException(
                'Active is not an operator recommendation lifecycle decision.',
            );
        }

        return DB::transaction(function () use (
            $recommendation,
            $operator,
            $status,
        ): OrchestrationRecommendation {
            $locked = OrchestrationRecommendation::query()
                ->whereKey($recommendation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatusValue = $locked->getRawOriginal('status');
            $currentStatus = is_string($currentStatusValue)
                ? OrchestrationRecommendationStatus::tryFrom($currentStatusValue)
                : null;

            if ($currentStatus === null) {
                throw new LogicException(
                    'The recommendation has an unsupported durable lifecycle state.',
                );
            }

            if ($currentStatus === $status) {
                return $locked;
            }

            if ($currentStatus !== OrchestrationRecommendationStatus::Active) {
                throw new LogicException(
                    'A finalized orchestration recommendation cannot receive another lifecycle decision.',
                );
            }

            $locked->forceFill([
                'status' => $status,
                'status_changed_by_user_id' => $operator->id,
                'status_changed_at' => now(),
            ])->save();

            $locked->loadMissing('project', 'task');

            $eventType = $status === OrchestrationRecommendationStatus::Dismissed
                ? 'orchestrator.recommendation_dismissed'
                : 'orchestrator.recommendation_superseded';

            $this->audit->record(
                $eventType,
                [
                    'recommendation_id' => $locked->id,
                    'agent_run_id' => $locked->agent_run_id,
                    'recommendation_type' => (string) $locked->getRawOriginal(
                        'recommendation_type',
                    ),
                    'evidence_hash' => $locked->evidence_hash,
                    'previous_status' => OrchestrationRecommendationStatus::Active->value,
                    'status' => $status->value,
                    'operator_user_id' => $operator->id,
                ],
                $locked->project,
                $locked->task,
            );

            return $locked->refresh();
        }, attempts: 3);
    }
}
