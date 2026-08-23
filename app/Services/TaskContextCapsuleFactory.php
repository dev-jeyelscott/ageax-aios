<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Task;
use App\Models\TaskAttempt;

class TaskContextCapsuleFactory
{
    public const int ReviewerRiskMapSchemaVersion = 1;

    private const int ReviewerRiskPolicyVersion = 1;

    private const int ReviewerRiskMaxEntries = 8;

    private const int ReviewerRiskMaxFilesPerEntry = 12;

    /** @var array<string, int> */
    private const array ReviewerRiskRanks = [
        'high' => 3,
        'medium' => 2,
        'low' => 1,
    ];

    public function __construct(private ObsidianProjectNotes $notes, private ProjectRuntimeCapabilityDetector $runtime) {}

    /** @return array<string, mixed> */
    public function make(Task $task, AgentRole $recipientRole = AgentRole::Coder): array
    {
        $task->loadMissing('project');
        $retrieval = $this->notes->taskRetrieval($task, $recipientRole);
        $previousAttempt = $task->attempts()
            ->latest('number')
            ->first(['id', 'task_id', 'number', 'base_sha', 'head_sha', 'commit_sha', 'status', 'validation_results', 'changed_files', 'log_path', 'finished_at']);
        $retrievalManifest = $retrieval['manifest'];

        if ($recipientRole === AgentRole::Reviewer) {
            $retrievalManifest['review_risk_map'] = $this->reviewRiskMap($task, $previousAttempt);
        }

        return [
            'task_key' => $task->key,
            'title' => $task->title,
            'objective' => $task->objective,
            'acceptance_criteria' => $task->acceptance_criteria,
            'implementation_prompt' => $task->implementation_prompt,
            'scope' => $task->scope,
            'constraints' => $task->constraints,
            'dependencies' => $task->dependencies()->pluck('key')->all(),
            'relevant_paths' => $task->relevant_paths,
            'verification_commands' => $task->verification_commands,
            'project_runtime_capabilities' => $this->runtime->detect($task->project),
            'previous_attempt' => $this->previousAttemptContext($previousAttempt, $recipientRole),
            'obsidian_project_knowledge' => $retrieval['notes'],
            'approved_documentation' => $retrieval['approved_patterns'],
            'retrieval_manifest' => $retrievalManifest,
            'operator_messages' => $task->operatorMessages()
                ->where('recipient_role', $recipientRole)
                ->whereNull('delivered_at')
                ->oldest()
                ->get(['id', 'body', 'created_at'])
                ->map(fn ($message): array => ['id' => $message->id, 'body' => $message->body, 'created_at' => $message->created_at?->toIso8601String()])
                ->all(),
            'review_findings' => $task->reviews()->latest()->with('findings')->first()?->findings->map(fn ($finding): array => $finding->only(['severity', 'location', 'current_implementation', 'expected_implementation', 'why_incorrect', 'required_fix', 'verification_requirement', 'implementation_fix_context']))->all() ?? [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function previousAttemptContext(?TaskAttempt $attempt, AgentRole $recipientRole): ?array
    {
        if ($attempt === null) {
            return null;
        }

        if ($recipientRole !== AgentRole::Coder) {
            return $attempt->only(['number', 'base_sha', 'head_sha', 'commit_sha', 'status', 'validation_results', 'changed_files', 'log_path', 'finished_at']);
        }

        $validationResults = json_decode((string) $attempt->getRawOriginal('validation_results'), true);
        $evidence = is_array($validationResults['evidence'] ?? null) ? $validationResults['evidence'] : [];
        $failedEvidence = collect($evidence)
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['passed'] ?? true) === false)
            ->all();

        return [
            ...$attempt->only(['number', 'base_sha', 'head_sha', 'commit_sha', 'status', 'changed_files', 'finished_at']),
            'failed_validation_evidence' => $failedEvidence,
        ];
    }

    /**
     * @return array{
     *     schema_version: int,
     *     policy_version: int,
     *     advisory_only: bool,
     *     usage: string,
     *     work_type: ?string,
     *     complexity: ?string,
     *     bounds: array{max_entries: int, max_files_per_entry: int},
     *     entries: list<array{area: string, risk: string, files: list<string>, reason: string}>,
     *     map_hash: string
     * }
     */
    private function reviewRiskMap(Task $task, ?TaskAttempt $attempt): array
    {
        $changedFiles = $this->changedFiles($attempt);
        $validationResults = $this->validationResults($attempt);
        $workType = $this->rawOptionalString($task, 'work_type');
        $complexity = $this->rawOptionalString($task, 'complexity');

        /** @var array<string, array{area: string, risk: string, files: list<string>, reason: string}> $entriesByArea */
        $entriesByArea = [];

        foreach ($changedFiles as $file) {
            $entry = $this->riskForFile($file);

            if ($complexity === 'high' && $entry['risk'] === 'low') {
                $entry['risk'] = 'medium';
                $entry['reason'] .= ' High task complexity elevates baseline review attention.';
            }

            $entry['files'] = [$file];
            $this->mergeRiskEntry($entriesByArea, $entry);
        }

        if ($workType === 'bug' && $changedFiles !== []) {
            $this->mergeRiskEntry($entriesByArea, [
                'area' => 'regression_behavior',
                'risk' => 'medium',
                'files' => [],
                'reason' => 'Bug work type warrants explicit regression scrutiny across the changed behavior.',
            ]);
        }

        $this->addValidationRiskEntries($entriesByArea, $validationResults);

        $entries = array_values($entriesByArea);

        foreach ($entries as &$entry) {
            $entry['files'] = array_values(array_unique($entry['files']));
            sort($entry['files'], SORT_STRING);
            $entry['files'] = array_slice($entry['files'], 0, self::ReviewerRiskMaxFilesPerEntry);
        }
        unset($entry);

        usort($entries, function (array $left, array $right): int {
            $risk = self::ReviewerRiskRanks[$right['risk']] <=> self::ReviewerRiskRanks[$left['risk']];

            return $risk !== 0
                ? $risk
                : strcmp($left['area'], $right['area']);
        });

        $entries = array_slice($entries, 0, self::ReviewerRiskMaxEntries);

        $map = [
            'schema_version' => self::ReviewerRiskMapSchemaVersion,
            'policy_version' => self::ReviewerRiskPolicyVersion,
            'advisory_only' => true,
            'usage' => 'Prioritize attention only; inspect the complete authorized diff and every changed file. High risk never implies rejection, and low risk never permits skipping evidence.',
            'work_type' => $workType,
            'complexity' => $complexity,
            'bounds' => [
                'max_entries' => self::ReviewerRiskMaxEntries,
                'max_files_per_entry' => self::ReviewerRiskMaxFilesPerEntry,
            ],
            'entries' => $entries,
        ];

        return [
            ...$map,
            'map_hash' => hash('sha256', $this->encode($map)),
        ];
    }

    /** @return list<string> */
    private function changedFiles(?TaskAttempt $attempt): array
    {
        $value = $attempt?->getAttribute('changed_files');

        if (! is_array($value)) {
            return [];
        }

        $files = [];

        foreach ($value as $file) {
            if (! is_string($file)) {
                continue;
            }

            $file = trim(str_replace('\\', '/', $file));

            if ($file !== '') {
                $files[] = $file;
            }
        }

        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, mixed> */
    private function validationResults(?TaskAttempt $attempt): array
    {
        $value = $attempt?->getAttribute('validation_results');

        return is_array($value) ? $value : [];
    }

    private function rawOptionalString(Task $task, string $attribute): ?string
    {
        $value = $task->getRawOriginal($attribute);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{area: string, risk: string, files: list<string>, reason: string} */
    private function riskForFile(string $file): array
    {
        $path = strtolower($file);

        if (
            str_starts_with($path, '.ai/rules/')
            || in_array($path, ['master-prompt.md', 'agents.md', 'claude.md'], true)
        ) {
            return $this->riskEntry(
                'governance_contract',
                'high',
                'Changes touch authoritative AIOS governance or path-scoped execution rules.',
            );
        }

        if (str_starts_with($path, 'tests/')) {
            return $this->riskEntry(
                'tests',
                'low',
                'Changes are test-focused; verify assertions remain aligned with the implementation contract.',
            );
        }

        if (
            str_starts_with($path, 'resources/js/')
            || str_starts_with($path, 'resources/css/')
        ) {
            return $this->riskEntry(
                'presentation',
                'low',
                'Changes are presentation-focused unless other durable evidence elevates review attention.',
            );
        }

        if (
            str_starts_with($path, 'docs/')
            || str_ends_with($path, '.md')
        ) {
            return $this->riskEntry(
                'documentation',
                'low',
                'Changes are documentation-focused unless other durable evidence elevates review attention.',
            );
        }

        if (
            str_starts_with($path, 'app/policies/')
            || str_starts_with($path, 'app/http/middleware/')
            || str_contains($path, '/auth/')
            || str_contains($path, 'authorization')
        ) {
            return $this->riskEntry(
                'authorization_security',
                'high',
                'Changes touch authentication, authorization, policy, or middleware boundaries.',
            );
        }

        if ($this->pathContainsAny($path, [
            'workspacepathresolver',
            'sanitizedexecutionenvironment',
            'ticketattachmentstorage',
            'credential',
            'secret',
        ])) {
            return $this->riskEntry(
                'security_boundary',
                'high',
                'Changes touch workspace, process-environment, attachment, credential, or secret-protection boundaries.',
            );
        }

        if (
            str_starts_with($path, 'database/migrations/')
            || $this->pathContainsAny($path, [
                'databaseprotection',
                'projectdatabaseisolation',
                'databasebackup',
            ])
        ) {
            return $this->riskEntry(
                'data_integrity',
                'high',
                'Changes touch schema, migration, backup, or database-isolation behavior.',
            );
        }

        if ($this->pathContainsAny($path, [
            'contextbudget',
            'contextcostestimator',
            'agentcontextassembler',
            'taskcontextcapsulefactory',
            'ticketcontextcapsulefactory',
            'harnesscapabilities',
        ])) {
            return $this->riskEntry(
                'context_budget',
                'high',
                'Changes touch AIOS-owned context assembly, capacity, estimation, or budget enforcement.',
            );
        }

        if ($this->pathContainsAny($path, [
            'recovery',
            'taskcommitter',
            'projectgitstate',
            'coderrepositoryguard',
            'dirtyrepository',
            'gitworkingtree',
        ])) {
            return $this->riskEntry(
                'git_recovery',
                'high',
                'Changes touch Git isolation, commit lifecycle, repository ownership, or recovery behavior.',
            );
        }

        if ($this->pathContainsAny($path, [
            'workflow',
            'workerlease',
            'agentworker',
            'workerheartbeat',
            'staleworker',
            'runcodertask',
            'runreviewertask',
            'runprojectmanager',
            'taskcontractguard',
            'taskvalidator',
            'taskclaim',
            'tickettriageclaim',
        ])) {
            return $this->riskEntry(
                'workflow_orchestration',
                'high',
                'Changes touch AIOS-owned workflow transitions, claiming, leases, workers, or execution ordering.',
            );
        }

        if (str_starts_with($path, 'app/models/')) {
            return $this->riskEntry(
                'data_model',
                'medium',
                'Changes touch persisted model behavior or relationships.',
            );
        }

        if (
            str_starts_with($path, 'config/')
            || str_starts_with($path, 'bootstrap/')
            || str_starts_with($path, 'routes/')
        ) {
            return $this->riskEntry(
                'application_configuration',
                'medium',
                'Changes touch application configuration, bootstrap, or routing behavior.',
            );
        }

        return $this->riskEntry(
            'application_code',
            'medium',
            'Changes affect executable application code outside a more specific elevated-risk boundary.',
        );
    }

    /** @return array{area: string, risk: string, files: list<string>, reason: string} */
    private function riskEntry(string $area, string $risk, string $reason): array
    {
        return [
            'area' => $area,
            'risk' => $risk,
            'files' => [],
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, array{area: string, risk: string, files: list<string>, reason: string}>  $entriesByArea
     * @param  array{area: string, risk: string, files: list<string>, reason: string}  $entry
     */
    private function mergeRiskEntry(array &$entriesByArea, array $entry): void
    {
        $existing = $entriesByArea[$entry['area']] ?? null;

        if ($existing === null) {
            $entriesByArea[$entry['area']] = $entry;

            return;
        }

        $existingRank = self::ReviewerRiskRanks[$existing['risk']];
        $incomingRank = self::ReviewerRiskRanks[$entry['risk']];

        $entriesByArea[$entry['area']] = [
            'area' => $entry['area'],
            'risk' => $incomingRank > $existingRank ? $entry['risk'] : $existing['risk'],
            'files' => [...$existing['files'], ...$entry['files']],
            'reason' => $incomingRank > $existingRank ? $entry['reason'] : $existing['reason'],
        ];
    }

    /**
     * @param  array<string, array{area: string, risk: string, files: list<string>, reason: string}>  $entriesByArea
     * @param  array<string, mixed>  $validationResults
     */
    private function addValidationRiskEntries(array &$entriesByArea, array $validationResults): void
    {
        $checks = $validationResults['checks'] ?? null;

        if (! is_array($checks)) {
            return;
        }

        $signals = [
            'secret_scan' => [
                'area' => 'security_validation',
                'reason' => 'Persisted secret-scan validation is false; inspect the durable validation evidence carefully.',
            ],
            'forbidden_file_check' => [
                'area' => 'security_validation',
                'reason' => 'Persisted forbidden-file validation is false; inspect repository safety evidence carefully.',
            ],
            'git_diff_check' => [
                'area' => 'repository_integrity',
                'reason' => 'Persisted Git diff validation is false; inspect exact Git evidence carefully.',
            ],
            'task_verification' => [
                'area' => 'deterministic_validation',
                'reason' => 'Persisted task verification is false; inspect deterministic verification evidence carefully.',
            ],
        ];

        foreach ($signals as $check => $signal) {
            if (($checks[$check] ?? null) !== false) {
                continue;
            }

            $this->mergeRiskEntry($entriesByArea, [
                'area' => $signal['area'],
                'risk' => 'high',
                'files' => [],
                'reason' => $signal['reason'],
            ]);
        }
    }

    /** @param list<string> $needles */
    private function pathContainsAny(string $path, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
