<?php

namespace App\Actions;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\ProjectReconciliationRun;
use App\ProjectReconciliationStatus;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

class RecordProjectReconciliationResult
{
    private const array AllowedFields = [
        'project_status',
        'functionality_summary',
        'new_functionality',
        'changed_functionality',
        'removed_functionality',
        'documentation_drift',
        'obsidian_findings',
        'risks',
        'recommended_actions',
    ];

    public function __construct(private AuditLogger $audit) {}

    /**
     * Validate and normalize one Project Manager reconciliation advisory response before durable
     * persistence. The Project Manager is strictly read-only advisory here: this method never
     * touches the repository, Obsidian, Skills, rules, Tasks, or Git state.
     *
     * @param  array<string, mixed>  $structuredResult
     * @return array{
     *     project_status: string,
     *     functionality_summary: string,
     *     new_functionality: list<string>,
     *     changed_functionality: list<string>,
     *     removed_functionality: list<string>,
     *     documentation_drift: list<string>,
     *     obsidian_findings: list<string>,
     *     risks: list<string>,
     *     recommended_actions: list<string>
     * }
     */
    public function validate(array $structuredResult): array
    {
        $unexpected = array_values(array_diff(array_keys($structuredResult), self::AllowedFields));

        if ($unexpected !== []) {
            throw new LogicException('Project Manager reconciliation result contains unsupported fields: '.implode(', ', $unexpected).'.');
        }

        $rules = [
            'project_status' => ['required', 'string', 'max:4000'],
            'functionality_summary' => ['required', 'string', 'max:8000'],
        ];

        foreach (array_slice(self::AllowedFields, 2) as $listField) {
            // An empty list is a valid, common answer (e.g. no removed functionality), but
            // Laravel's "required" rule treats an empty array as absent; "present" only demands
            // the key exist, so a genuinely empty list still passes.
            $rules[$listField] = ['present', 'array'];
            $rules[$listField.'.*'] = ['required', 'string', 'max:2000'];
        }

        $validated = Validator::make($structuredResult, $rules)->validate();

        return [
            'project_status' => (string) $validated['project_status'],
            'functionality_summary' => (string) $validated['functionality_summary'],
            'new_functionality' => array_values($validated['new_functionality']),
            'changed_functionality' => array_values($validated['changed_functionality']),
            'removed_functionality' => array_values($validated['removed_functionality']),
            'documentation_drift' => array_values($validated['documentation_drift']),
            'obsidian_findings' => array_values($validated['obsidian_findings']),
            'risks' => array_values($validated['risks']),
            'recommended_actions' => array_values($validated['recommended_actions']),
        ];
    }

    /**
     * Persist only a validated, immutably attributed reconciliation result.
     *
     * @param  array<string, mixed>  $structuredResult
     */
    public function handle(ProjectReconciliationRun $run, AgentRun $agentRun, array $structuredResult): ProjectReconciliationRun
    {
        $validated = $this->validate($structuredResult);

        return DB::transaction(function () use ($run, $agentRun, $validated): ProjectReconciliationRun {
            $lockedRun = ProjectReconciliationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedAgentRun = AgentRun::query()->whereKey($agentRun->id)->lockForUpdate()->firstOrFail();

            $this->assertEligibleRun($lockedRun, $lockedAgentRun);

            $lockedRun->update([
                'result' => $validated,
                'status' => ProjectReconciliationStatus::Completed,
                'agent_run_id' => $lockedAgentRun->id,
                'finished_at' => now(),
            ]);

            $this->audit->record('reconciliation.completed', [
                'reconciliation_run_id' => $lockedRun->id,
                'agent_run_id' => $lockedAgentRun->id,
            ], $lockedRun->project);

            return $lockedRun->refresh();
        }, attempts: 3);
    }

    private function assertEligibleRun(ProjectReconciliationRun $run, AgentRun $agentRun): void
    {
        if ($run->getRawOriginal('status') !== ProjectReconciliationStatus::Running->value) {
            throw new LogicException('A reconciliation result may only be recorded for a running reconciliation run.');
        }

        if ((int) $agentRun->project_id !== (int) $run->project_id) {
            throw new LogicException('Reconciliation AgentRun cannot cross the project boundary.');
        }

        if ($agentRun->getRawOriginal('role') !== AgentRole::ProjectManager->value
            || $agentRun->getRawOriginal('status') !== AgentRunStatus::Completed->value
            || $agentRun->finished_at === null) {
            throw new LogicException('Reconciliation result requires a completed Project Manager AgentRun.');
        }
    }
}
