<?php

namespace App\Services;

use App\AgentRole;
use LogicException;

final class ContextBudgetPolicy
{
    public const int SchemaVersion = 1;

    public const int PolicyVersion = 1;

    public const int NormalTargetPercent = 70;

    public const int WarningPercent = 75;

    public const int HardCeilingPercent = 80;

    public const int ReservedPercent = 20;

    public const int MinimumProjectTargetPercent = 50;

    public const int MaximumProjectTargetPercent = 70;

    /** @var array<string, int> */
    private const array RoleTargets = [
        'project_manager' => self::NormalTargetPercent,
        'coder' => self::NormalTargetPercent,
        'reviewer' => self::NormalTargetPercent,
    ];

    /**
     * Retained source quotas are percentages of the resolved target token budget.
     * They are maximum retained shares once reduction is triggered; AIOS never
     * trims a protected source merely to consume the remaining quota.
     *
     * @var array<string, int>
     */
    public const array SourceQuotaPercents = [
        'agent_default_context' => 8,
        'skills' => 18,
        'repository_retrieval' => 20,
        'obsidian_context' => 10,
        'older_history' => 8,
    ];

    /**
     * @return array{
     *     schema_version: int,
     *     policy_version: int,
     *     role: string,
     *     role_target_percent: int,
     *     project_target_percent: int|null,
     *     target_source: string,
     *     target_percent: int,
     *     warning_percent: int,
     *     hard_ceiling_percent: int,
     *     reserved_percent: int,
     *     capacity_tokens: int,
     *     target_tokens: int,
     *     warning_tokens: int,
     *     hard_ceiling_tokens: int,
     *     source_quota_percents: array<string, int>,
     *     source_quota_tokens: array<string, int>
     * }
     */
    public function resolve(
        AgentRole $role,
        int $capacityTokens,
        ?int $projectTargetPercent = null,
    ): array {
        if ($capacityTokens <= 0) {
            throw new LogicException('Context capacity must be a positive token count.');
        }

        $roleTarget = self::RoleTargets[$role->value] ?? null;

        if ($roleTarget === null) {
            throw new LogicException(
                "Context Budget has no approved workflow-role target for [{$role->value}].",
            );
        }

        if (
            $projectTargetPercent !== null
            && ($projectTargetPercent < self::MinimumProjectTargetPercent
                || $projectTargetPercent > self::MaximumProjectTargetPercent)
        ) {
            throw new LogicException(
                'Project Context Budget target must stay between '
                .self::MinimumProjectTargetPercent
                .'% and '
                .self::MaximumProjectTargetPercent
                .'%.',
            );
        }

        $target = $projectTargetPercent ?? $roleTarget;
        $targetSource = $projectTargetPercent === null
            ? 'workflow_role_default'
            : 'bounded_project_override';

        $targetTokens = $this->tokensAtPercent($capacityTokens, $target);
        $sourceQuotaTokens = [];

        foreach (self::SourceQuotaPercents as $source => $percent) {
            $sourceQuotaTokens[$source] = $this->tokensAtPercent(
                $targetTokens,
                $percent,
            );
        }

        return [
            'schema_version' => self::SchemaVersion,
            'policy_version' => self::PolicyVersion,
            'role' => $role->value,
            'role_target_percent' => $roleTarget,
            'project_target_percent' => $projectTargetPercent,
            'target_source' => $targetSource,
            'target_percent' => $target,
            'warning_percent' => self::WarningPercent,
            'hard_ceiling_percent' => self::HardCeilingPercent,
            'reserved_percent' => self::ReservedPercent,
            'capacity_tokens' => $capacityTokens,
            'target_tokens' => $targetTokens,
            'warning_tokens' => $this->tokensAtPercent(
                $capacityTokens,
                self::WarningPercent,
            ),
            'hard_ceiling_tokens' => $this->tokensAtPercent(
                $capacityTokens,
                self::HardCeilingPercent,
            ),
            'source_quota_percents' => self::SourceQuotaPercents,
            'source_quota_tokens' => $sourceQuotaTokens,
        ];
    }

    private function tokensAtPercent(int $tokens, int $percent): int
    {
        return (int) floor($tokens * ($percent / 100));
    }
}

