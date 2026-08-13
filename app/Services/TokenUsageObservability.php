<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Project;

class TokenUsageObservability
{
    /** @return array<string, array{rolling_average: ?int, baseline_average: ?int, change_percentage: ?float, run_count: int, warning_threshold: int}> */
    public function forProject(Project $project): array
    {
        return collect([AgentRole::Coder, AgentRole::Reviewer])
            ->mapWithKeys(fn (AgentRole $role): array => [$role->value => $this->forRole($project, $role)])
            ->all();
    }

    /** @return array{rolling_average: ?int, baseline_average: ?int, change_percentage: ?float, run_count: int, warning_threshold: int} */
    private function forRole(Project $project, AgentRole $role): array
    {
        $window = max(1, (int) config('aios.token_observability_window', 20));
        $usage = $project->runs()
            ->where('role', $role)
            ->whereNotNull('token_usage')
            ->latest('finished_at')
            ->limit($window * 2)
            ->pluck('token_usage')
            ->map(fn (mixed $tokens): int => (int) $tokens)
            ->all();
        $rolling = array_slice($usage, 0, $window);
        $baseline = array_slice($usage, $window, $window);
        $rollingAverage = $this->average($rolling);
        $baselineAverage = $this->average($baseline);

        return [
            'rolling_average' => $rollingAverage,
            'baseline_average' => $baselineAverage,
            'change_percentage' => $baselineAverage === null || $baselineAverage === 0 || $rollingAverage === null ? null : round((($rollingAverage - $baselineAverage) / $baselineAverage) * 100, 1),
            'run_count' => count($rolling),
            'warning_threshold' => (int) config($role === AgentRole::Coder ? 'aios.token_warning_coder' : 'aios.token_warning_reviewer'),
        ];
    }

    /** @param array<int, int> $values */
    private function average(array $values): ?int
    {
        return $values === [] ? null : (int) round(array_sum($values) / count($values));
    }
}
