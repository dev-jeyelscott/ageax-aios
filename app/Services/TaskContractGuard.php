<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAttempt;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final readonly class TaskContractGuard
{
    public const int SchemaVersion = 1;

    public function __construct(
        private Filesystem $files,
        private WorkspacePathResolver $paths,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     drifted: bool,
     *     baseline: ?array{schema_version: int, fingerprint: string, input_hashes: array<string, mixed>},
     *     current: array{schema_version: int, fingerprint: string, input_hashes: array<string, mixed>},
     *     baseline_attempt_number: ?int,
     *     changed_inputs: list<string>,
     *     recovery_pinned: bool
     * }
     */
    public function evaluate(Task $task, array $context, ?TaskAttempt $requiredBaselineAttempt = null): array
    {
        $current = $this->evidence($task, $context);
        $baselineAttempt = $requiredBaselineAttempt ?? $this->normalBaselineAttempt($task);
        $baseline = $baselineAttempt === null ? null : $this->attemptEvidence($baselineAttempt);

        if ($requiredBaselineAttempt !== null && $baseline === null) {
            return [
                'drifted' => true,
                'baseline' => null,
                'current' => $current,
                'baseline_attempt_number' => (int) $requiredBaselineAttempt->number,
                'changed_inputs' => ['contract_baseline_missing'],
                'recovery_pinned' => true,
            ];
        }

        $changedInputs = $baseline === null
            ? []
            : $this->changedInputs($baseline['input_hashes'], $current['input_hashes']);

        return [
            'drifted' => $baseline !== null && ! hash_equals($baseline['fingerprint'], $current['fingerprint']),
            'baseline' => $baseline,
            'current' => $current,
            'baseline_attempt_number' => $baselineAttempt === null ? null : (int) $baselineAttempt->number,
            'changed_inputs' => $changedInputs,
            'recovery_pinned' => $requiredBaselineAttempt !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{schema_version: int, fingerprint: string, input_hashes: array<string, mixed>}
     */
    public function evidence(Task $task, array $context): array
    {
        $task->loadMissing('project');

        $inputHashes = [
            'objective' => $this->hashValue($this->normalizeText($task->objective)),
            'acceptance_criteria' => $this->hashValue($this->normalizeStringList($task->acceptance_criteria, true)),
            'scope' => $this->hashValue($this->normalizeStringList($task->scope, true)),
            'constraints' => $this->hashValue($this->normalizeStringList($task->constraints, true)),
            'implementation_prompt' => $this->hashValue($this->normalizeText($task->implementation_prompt)),
            'verification_commands' => $this->hashValue($this->normalizeStringList($task->verification_commands, false)),
            'relevant_paths' => $this->hashValue($this->normalizeStringList($task->relevant_paths, true)),
            'repository_documents' => $this->repositoryDocumentHashes($task),
            'obsidian_notes' => $this->obsidianNoteHashes($context),
            'obsidian_selection' => $this->hashValue($this->selectedObsidianPaths($context)),
        ];

        return [
            'schema_version' => self::SchemaVersion,
            'fingerprint' => $this->hashValue([
                'schema_version' => self::SchemaVersion,
                'input_hashes' => $inputHashes,
            ]),
            'input_hashes' => $inputHashes,
        ];
    }

    /** @return array{schema_version: int, fingerprint: string, input_hashes: array<string, mixed>}|null */
    public function attemptEvidence(TaskAttempt $attempt): ?array
    {
        $validation = $this->decodedObject($attempt->getRawOriginal('validation_results'));
        $evidence = $validation['task_contract'] ?? null;

        if (
            ! is_array($evidence)
            || ! is_int($evidence['schema_version'] ?? null)
            || ! is_string($evidence['fingerprint'] ?? null)
            || ! is_array($evidence['input_hashes'] ?? null)
        ) {
            return null;
        }

        return [
            'schema_version' => $evidence['schema_version'],
            'fingerprint' => $evidence['fingerprint'],
            'input_hashes' => $evidence['input_hashes'],
        ];
    }

    private function normalBaselineAttempt(Task $task): ?TaskAttempt
    {
        $latestDriftId = $task->auditEvents()
            ->where('event_type', 'task.contract_drift_detected')
            ->max('id');

        if ($latestDriftId !== null) {
            $requeuedAfterDrift = $task->auditEvents()
                ->where('event_type', 'task.requeued')
                ->where('id', '>', $latestDriftId)
                ->exists();

            if ($requeuedAfterDrift) {
                return null;
            }
        }

        return $task->attempts()
            ->orderByDesc('number')
            ->get()
            ->first(fn (TaskAttempt $attempt): bool => $this->attemptEvidence($attempt) !== null);
    }

    /** @return array<string, string> */
    private function repositoryDocumentHashes(Task $task): array
    {
        $projectPath = $this->paths->assertProjectPath($task->project->path);
        $relevantPaths = $this->normalizeStringList($task->relevant_paths, true);
        $candidates = [
            'MASTER-PROMPT.md',
            'AGENTS.md',
            'CLAUDE.md',
            '.ai/rules/index.md',
        ];

        foreach ($relevantPaths as $path) {
            if ($this->isDocumentationPath($path)) {
                $candidates[] = $path;
            }
        }

        $ruleIndexPath = $this->safeProjectFile($projectPath, '.ai/rules/index.md');
        if ($ruleIndexPath !== null) {
            foreach ($this->applicableRulePaths($this->files->get($ruleIndexPath), $relevantPaths) as $rulePath) {
                $candidates[] = $rulePath;
            }
        }

        $hashes = [];
        foreach (array_values(array_unique($candidates)) as $relativePath) {
            $path = $this->safeProjectFile($projectPath, $relativePath);
            if ($path === null) {
                continue;
            }

            $hashes[$relativePath] = hash('sha256', $this->normalizeFileContent($this->files->get($path)));
        }

        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private function obsidianNoteHashes(array $context): array
    {
        $notes = is_array($context['obsidian_project_knowledge'] ?? null)
            ? $context['obsidian_project_knowledge']
            : [];
        $hashes = [];

        foreach ($notes as $path => $content) {
            if (! is_string($path) || $path === 'STATE.md' || ! is_string($content)) {
                continue;
            }

            $hashes[$path] = hash('sha256', $this->normalizeFileContent($content));
        }

        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function selectedObsidianPaths(array $context): array
    {
        $manifest = is_array($context['retrieval_manifest'] ?? null) ? $context['retrieval_manifest'] : [];
        $paths = $manifest['selected_note_paths'] ?? [];

        if (! is_array($paths)) {
            return [];
        }

        $selected = array_values(array_unique(array_filter(
            $paths,
            fn (mixed $path): bool => is_string($path) && $path !== '' && $path !== 'STATE.md',
        )));
        sort($selected, SORT_STRING);

        return $selected;
    }

    /**
     * @param  list<string>  $relevantPaths
     * @return list<string>
     */
    private function applicableRulePaths(string $indexContent, array $relevantPaths): array
    {
        $rules = [];

        foreach (preg_split('/\R/', $indexContent) ?: [] as $line) {
            if (! preg_match('/^\|\s*`?([^|`]+)`?\s*\|\s*`?([^|`]+\.md)`?\s*\|/', trim($line), $matches)) {
                continue;
            }

            $glob = trim($matches[1]);
            $rulePath = trim($matches[2]);

            if ($glob === '' || $rulePath === '' || str_contains(strtolower($glob), 'applies')) {
                continue;
            }

            if (collect($relevantPaths)->contains(fn (string $path): bool => $this->globMatches($glob, $path))) {
                $rules[] = Str::startsWith($rulePath, '.ai/rules/') ? $rulePath : '.ai/rules/'.$rulePath;
            }
        }

        $rules = array_values(array_unique($rules));
        sort($rules, SORT_STRING);

        return $rules;
    }

    private function globMatches(string $glob, string $path): bool
    {
        $pattern = preg_quote(str_replace('\\', '/', trim($glob)), '/');
        $pattern = str_replace('\\*\\*', '.*', $pattern);
        $pattern = str_replace('\\*', '[^/]*', $pattern);
        $pattern = str_replace('\\?', '[^/]', $pattern);

        return preg_match('/^'.$pattern.'$/', str_replace('\\', '/', $path)) === 1;
    }

    private function safeProjectFile(string $projectPath, string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));

        if (
            $relativePath === ''
            || Str::contains($relativePath, ['..', "\0"])
            || Str::startsWith($relativePath, '/')
        ) {
            return null;
        }

        $resolvedProjectPath = realpath($projectPath);
        $resolved = realpath($projectPath.'/'.$relativePath);

        if (
            $resolvedProjectPath === false
            || $resolved === false
            || ! Str::startsWith($resolved, $resolvedProjectPath.DIRECTORY_SEPARATOR)
            || ! $this->files->isFile($resolved)
        ) {
            return null;
        }

        return $resolved;
    }

    private function isDocumentationPath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));

        return Str::startsWith($normalized, ['docs/', 'documentation/', 'specs/', 'specifications/', '.ai/rules/'])
            || Str::endsWith($normalized, ['.md', '.txt', '.rst', '.adoc']);
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $values, bool $sort): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) ? $this->normalizeText($value) : null,
            $values,
        ), fn (?string $value): bool => $value !== null && $value !== '')));

        if ($sort) {
            sort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    private function normalizeText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        $normalized = preg_replace('/[\t ]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/ *\n */u', "\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function normalizeFileContent(string $content): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $content));
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function changedInputs(array $baseline, array $current): array
    {
        $changed = [];
        $keys = array_values(array_unique([...array_keys($baseline), ...array_keys($current)]));
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $before = $baseline[$key] ?? null;
            $after = $current[$key] ?? null;

            if (is_array($before) || is_array($after)) {
                $beforeMap = is_array($before) ? $before : [];
                $afterMap = is_array($after) ? $after : [];
                $nestedKeys = array_values(array_unique([...array_keys($beforeMap), ...array_keys($afterMap)]));
                sort($nestedKeys, SORT_STRING);

                foreach ($nestedKeys as $nestedKey) {
                    if (($beforeMap[$nestedKey] ?? null) !== ($afterMap[$nestedKey] ?? null)) {
                        $changed[] = $key.':'.(string) $nestedKey;
                    }
                }

                continue;
            }

            if ($before !== $after) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    private function hashValue(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function decodedObject(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
