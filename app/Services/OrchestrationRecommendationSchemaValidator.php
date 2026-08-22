<?php

namespace App\Services;

use App\AgentHarness;
use App\AgentRole;
use App\OrchestrationRecommendationType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OrchestrationRecommendationSchemaValidator
{
    public const int SchemaVersion = 1;

    /**
     * Keys representing authority or executable behavior that recommendation payloads
     * must never expose as durable operational controls.
     *
     * @var list<string>
     */
    private const array ForbiddenAuthorityKeys = [
        'apply',
        'apply_now',
        'execute',
        'command',
        'commands',
        'shell',
        'script',
        'code',
        'php',
        'permission',
        'permissions',
        'worker',
        'worker_id',
        'agent_worker_id',
        'project_id',
        'task_id',
        'recovery_incident_id',
        'task_status',
        'status',
        'transition',
        'git',
        'git_command',
        'workflow_definition',
    ];

    /**
     * Validate and return a bounded structured recommendation for one schema version and type.
     *
     * @param  array<string, mixed>  $structuredRecommendation
     * @return array<string, mixed>
     */
    public function validate(
        OrchestrationRecommendationType $type,
        int $schemaVersion,
        array $structuredRecommendation,
    ): array {
        if ($schemaVersion !== self::SchemaVersion) {
            throw ValidationException::withMessages([
                'schema_version' => "Unsupported orchestration recommendation schema version [{$schemaVersion}].",
            ]);
        }

        $this->assertNoAuthorityKeys($structuredRecommendation);

        $validated = Validator::make(
            ['recommendation' => $structuredRecommendation],
            $this->rulesFor($type),
        )->validate();

        $recommendation = $validated['recommendation'] ?? null;

        if (! is_array($recommendation)) {
            throw ValidationException::withMessages([
                'structured_recommendation' => 'A structured recommendation is required.',
            ]);
        }

        return $recommendation;
    }

    /**
     * Return the exact allowlisted validation contract for a recommendation type.
     *
     * @return array<string, mixed>
     */
    private function rulesFor(OrchestrationRecommendationType $type): array
    {
        return match ($type) {
            OrchestrationRecommendationType::AgentConfiguration => [
                'recommendation' => [
                    'required',
                    'array:target_role,changes,reason',
                ],
                'recommendation.target_role' => [
                    'required',
                    'string',
                    Rule::in($this->agentRoleValues()),
                ],
                'recommendation.changes' => [
                    'required',
                    'array:harness,model,reasoning_setting,default_context',
                    'min:1',
                ],
                'recommendation.changes.harness' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::in($this->harnessValues()),
                ],
                'recommendation.changes.model' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:120',
                ],
                'recommendation.changes.reasoning_setting' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:80',
                ],
                'recommendation.changes.default_context' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:2000',
                ],
                'recommendation.reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ],

            OrchestrationRecommendationType::HarnessModel => [
                'recommendation' => [
                    'required',
                    'array:target_role,harness,model,reason',
                ],
                'recommendation.target_role' => [
                    'required',
                    'string',
                    Rule::in($this->agentRoleValues()),
                ],
                'recommendation.harness' => [
                    'required',
                    'string',
                    Rule::in($this->harnessValues()),
                ],
                'recommendation.model' => [
                    'present',
                    'nullable',
                    'string',
                    'max:120',
                ],
                'recommendation.reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ],

            OrchestrationRecommendationType::ReasoningLevel => [
                'recommendation' => [
                    'required',
                    'array:target_role,reasoning_setting,reason',
                ],
                'recommendation.target_role' => [
                    'required',
                    'string',
                    Rule::in($this->agentRoleValues()),
                ],
                'recommendation.reasoning_setting' => [
                    'present',
                    'nullable',
                    'string',
                    'max:80',
                ],
                'recommendation.reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ],

            OrchestrationRecommendationType::RetryStrategy => [
                'recommendation' => [
                    'required',
                    'array:strategy,max_attempts,backoff_seconds,reason',
                ],
                'recommendation.strategy' => [
                    'required',
                    'string',
                    'max:120',
                ],
                'recommendation.max_attempts' => [
                    'required',
                    'integer',
                    'min:1',
                    'max:10',
                ],
                'recommendation.backoff_seconds' => [
                    'sometimes',
                    'integer',
                    'min:0',
                    'max:3600',
                ],
                'recommendation.reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ],

            OrchestrationRecommendationType::ContextStrategy => [
                'recommendation' => [
                    'required',
                    'array:strategy,include_sources,exclude_sources,reason',
                ],
                'recommendation.strategy' => [
                    'required',
                    'string',
                    'max:120',
                ],
                'recommendation.include_sources' => [
                    'sometimes',
                    'array',
                    'max:20',
                ],
                'recommendation.include_sources.*' => [
                    'string',
                    'max:160',
                ],
                'recommendation.exclude_sources' => [
                    'sometimes',
                    'array',
                    'max:20',
                ],
                'recommendation.exclude_sources.*' => [
                    'string',
                    'max:160',
                ],
                'recommendation.reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ],

            OrchestrationRecommendationType::TaskDecomposition => [
                'recommendation' => [
                    'required',
                    'array:summary,tasks,reason',
                ],
                'recommendation.summary' => [
                    'required',
                    'string',
                    'max:2000',
                ],
                'recommendation.tasks' => [
                    'required',
                    'array',
                    'min:1',
                    'max:10',
                ],
                'recommendation.tasks.*' => [
                    'required',
                    'array:title,objective',
                ],
                'recommendation.tasks.*.title' => [
                    'required',
                    'string',
                    'max:200',
                ],
                'recommendation.tasks.*.objective' => [
                    'required',
                    'string',
                    'max:1000',
                ],
                'recommendation.reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ],

            OrchestrationRecommendationType::RecoveryDirection => [
                'recommendation' => [
                    'required',
                    'array:direction,reason',
                ],
                'recommendation.direction' => [
                    'required',
                    'string',
                    'max:500',
                ],
                'recommendation.reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ],

            OrchestrationRecommendationType::WorkflowImprovement => [
                'recommendation' => [
                    'required',
                    'array:summary,proposed_change,reason',
                ],
                'recommendation.summary' => [
                    'required',
                    'string',
                    'max:1000',
                ],
                'recommendation.proposed_change' => [
                    'required',
                    'string',
                    'max:2000',
                ],
                'recommendation.reason' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ],
        };
    }

    /**
     * Reject nested fields that attempt to encode direct execution or durable authority.
     *
     * @param  array<mixed>  $payload
     */
    private function assertNoAuthorityKeys(
        array $payload,
        string $path = 'structured_recommendation',
    ): void {
        foreach ($payload as $key => $value) {
            $nestedPath = $path;

            if (is_string($key)) {
                $normalizedKey = Str::snake($key);
                $nestedPath .= '.'.$key;

                if (in_array($normalizedKey, self::ForbiddenAuthorityKeys, true)) {
                    throw ValidationException::withMessages([
                        $nestedPath => "Recommendation field [{$key}] is not allowed to carry AIOS authority.",
                    ]);
                }
            }

            if (is_array($value)) {
                $this->assertNoAuthorityKeys($value, $nestedPath);
            }
        }
    }

    /**
     * Return every currently defined Agent role as an advisory target value.
     *
     * @return list<string>
     */
    private function agentRoleValues(): array
    {
        return array_map(
            static fn (AgentRole $role): string => $role->value,
            AgentRole::cases(),
        );
    }

    /**
     * Return the harness identifiers supported by the existing AIOS harness boundary.
     *
     * @return list<string>
     */
    private function harnessValues(): array
    {
        return array_map(
            static fn (AgentHarness $harness): string => $harness->value,
            AgentHarness::cases(),
        );
    }
}
