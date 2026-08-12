<?php

namespace Tests\Support;

use App\Services\GitRepositoryInspector;

class LegacyWorkflowGitRepositoryInspector extends GitRepositoryInspector
{
    /**
     * @return array{
     *     inspectable: bool,
     *     clean: bool,
     *     head_sha: ?string,
     *     index_files: array<int, string>,
     *     working_tree_files: array<int, string>,
     *     untracked_files: array<int, string>,
     *     error: ?string
     * }
     */
    public function inspect(string $projectPath): array
    {
        if ($this->isLegacyFixture($projectPath)) {
            return [
                'inspectable' => true,
                'clean' => true,
                'head_sha' => 'legacy-workflow-base-sha',
                'index_files' => [],
                'working_tree_files' => [],
                'untracked_files' => [],
                'error' => null,
            ];
        }

        return parent::inspect($projectPath);
    }

    /** @return array<int, string>|null */
    public function changedFilesFromBase(string $projectPath, string $baseSha): ?array
    {
        if ($this->isLegacyFixture($projectPath)) {
            return [];
        }

        return parent::changedFilesFromBase($projectPath, $baseSha);
    }

    private function isLegacyFixture(string $projectPath): bool
    {
        return str_starts_with($projectPath, sys_get_temp_dir().'/example-');
    }
}
