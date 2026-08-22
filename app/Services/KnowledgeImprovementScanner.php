<?php

namespace App\Services;

use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\AuditEvent;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\ReviewFinding;
use App\Models\Skill;
use App\Models\Task;
use App\Models\TaskAttempt;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KnowledgeImprovementScanner
{
    private const int MaxStoredEvidenceReferences = 100;

    /** @var list<string> */
    private const array OperationalValidationChecks = [
        'agent_binding',
        'codex_execution',
        'execution_exception',
        'repository_preflight',
        'task_commit',
        'task_contract',
        'workspace_path',
    ];

    /** @var array<string, list<string>> */
    private const array ReviewFamilies = [
        'security_authorization' => ['authorization', 'permission', 'policy', 'access control', 'secret', 'credential', 'path traversal', 'symlink'],
        'transactional_integrity' => ['transaction', 'atomic', 'lockforupdate', 'lock for update', 'race condition', 'idempotent'],
        'workflow_ownership' => ['workflow state', 'state transition', 'agentworker', 'aios-owned', 'orchestration', 'worker lease'],
        'git_isolation' => ['git', 'dirty repository', 'working tree', 'staged', 'commit sha', 'base sha'],
        'validation_regression' => ['regression', 'test coverage', 'validation', 'phpstan', 'lint', 'type check'],
        'context_determinism' => ['context snapshot', 'context hash', 'skill order', 'configuration snapshot', 'context assembly'],
        'architecture_consistency' => ['architecture', 'parallel system', 'duplicate service', 'existing pattern', 'framework-native'],
    ];

    /**
     * Inject durable candidate auditing plus deterministic point-gap detection.
     */
    public function __construct(
        private AuditLogger $audit,
        private KnowledgeGapDetector $knowledgeGaps,
    ) {}

    /**
     * Scan deterministic recurring evidence and objective point defects into the existing candidate queue.
     */
    public function scan(Project $project): int
    {
        $groups = [];

        foreach ($this->reviewFindingItems($project) as $item) {
            $this->addToGroup($groups, $item);
        }

        foreach ($this->validationFailureItems($project) as $item) {
            $this->addToGroup($groups, $item);
        }

        foreach ($this->auditBlockItems($project) as $item) {
            $this->addToGroup($groups, $item);
        }

        foreach ($this->recoveryIncidentItems($project) as $item) {
            $this->addToGroup($groups, $item);
        }

        foreach ($this->knowledgeGaps->detect($project) as $item) {
            $this->addToGroup($groups, $item);
        }

        $changed = 0;

        foreach ($groups as $fingerprint => $group) {
            if (count($group['references']) < $group['minimum_occurrences']) {
                continue;
            }

            if ($this->persistGroup($project, $fingerprint, $group)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Normalize actionable Reviewer findings into recurring deterministic evidence.
     *
     * @return list<array{
     *     fingerprint_payload: array<string, mixed>,
     *     source_kind: string,
     *     failure_code: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string,
     *     reference: array<string, mixed>,
     *     occurred_at: string
     * }>
     */
    private function reviewFindingItems(Project $project): array
    {
        $items = [];

        $findings = ReviewFinding::query()
            ->whereHas('review.task', fn ($query) => $query->where('project_id', $project->id))
            ->where('created_at', '>=', $this->since())
            ->with([
                'review:id,task_id,task_attempt_id,status',
                'review.task:id,project_id,key',
            ])
            ->latest('id')
            ->limit($this->scanLimit())
            ->get();

        foreach ($findings as $finding) {
            $review = $finding->review;
            $task = $review?->task;

            if ($review === null || ! $task instanceof Task || $review->getRawOriginal('status') !== 'changes_required') {
                continue;
            }

            $family = $this->reviewFamily($finding);
            $area = $this->areaFromLocation($finding->location);
            $severity = Str::lower(Str::squish((string) $finding->severity));
            $failureCode = 'review_finding:'.$family.':'.($severity !== '' ? $severity : 'unspecified');
            $targetSkillSlug = $this->reviewTargetSkillSlug($family);

            $items[] = [
                'fingerprint_payload' => [
                    'source_kind' => 'review_finding',
                    'failure_code' => $failureCode,
                    'affected_role' => 'coder',
                    'affected_area' => $area,
                ],
                'source_kind' => 'review_finding',
                'failure_code' => $failureCode,
                'affected_role' => 'coder',
                'affected_area' => $area,
                'target_type' => KnowledgeImprovementTarget::Skill,
                'target_skill_slug' => $targetSkillSlug,
                'proposed_change' => "Recurring {$family} review findings are reaching review in {$area}. Before completing work in this area, explicitly verify the applicable task contract and project rules for this failure family and include focused deterministic regression evidence for the corrected behavior.",
                'reference' => [
                    'source_type' => 'review_finding',
                    'source_id' => $finding->id,
                    'task_id' => $task->id,
                    'task_key' => $task->key,
                    'review_id' => $review->id,
                    'task_attempt_id' => $review->task_attempt_id,
                ],
                'occurred_at' => $this->dateValue($finding, 'created_at'),
            ];
        }

        return $items;
    }

    /**
     * Normalize failed deterministic Task validation checks into recurring evidence.
     *
     * @return list<array{
     *     fingerprint_payload: array<string, mixed>,
     *     source_kind: string,
     *     failure_code: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string,
     *     reference: array<string, mixed>,
     *     occurred_at: string
     * }>
     */
    private function validationFailureItems(Project $project): array
    {
        $items = [];

        $attempts = TaskAttempt::query()
            ->whereHas('task', fn ($query) => $query->where('project_id', $project->id))
            ->where('status', 'failed')
            ->where('finished_at', '>=', $this->since())
            ->with('task:id,project_id,key')
            ->latest('id')
            ->limit($this->scanLimit())
            ->get();

        foreach ($attempts as $attempt) {
            $task = $attempt->task;

            if (! $task instanceof Task) {
                continue;
            }

            $validation = $this->arrayAttribute(
                $attempt,
                'validation_results',
            );
            $checks = $this->failedDeterministicChecks($validation);

            if ($checks === []) {
                continue;
            }

            $area = $this->validationArea(
                $validation,
                $this->stringListAttribute($attempt, 'changed_files'),
            );
            $failureCode = 'validation:'.implode('+', $checks);
            $targetSkillSlug = $this->validationTargetSkillSlug($checks);

            $items[] = [
                'fingerprint_payload' => [
                    'source_kind' => 'validation_failure',
                    'failure_code' => $failureCode,
                    'affected_role' => 'coder',
                    'affected_area' => $area,
                ],
                'source_kind' => 'validation_failure',
                'failure_code' => $failureCode,
                'affected_role' => 'coder',
                'affected_area' => $area,
                'target_type' => KnowledgeImprovementTarget::Skill,
                'target_skill_slug' => $targetSkillSlug,
                'proposed_change' => 'Before handing a Coder task to review, explicitly resolve and rerun the recurring deterministic checks ['.implode(', ', $checks)."] for {$area}; do not treat the task as ready until those checks pass with durable verification evidence.",
                'reference' => [
                    'source_type' => 'task_attempt',
                    'source_id' => $attempt->id,
                    'task_id' => $task->id,
                    'task_key' => $task->key,
                    'task_attempt_id' => $attempt->id,
                    'attempt_number' => $attempt->number,
                ],
                'occurred_at' => $this->dateValue($attempt, 'finished_at'),
            ];
        }

        return $items;
    }

    /**
     * Normalize known AIOS audit blocks into recurring deterministic evidence.
     *
     * @return list<array{
     *     fingerprint_payload: array<string, mixed>,
     *     source_kind: string,
     *     failure_code: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string,
     *     reference: array<string, mixed>,
     *     occurred_at: string
     * }>
     */
    private function auditBlockItems(Project $project): array
    {
        $items = [];
        $eventTypes = [
            'task.blocked_dirty_repository',
            'task.blocked_unsafe_path',
            'task.blocked_agent_misconfigured',
            'task.contract_drift_detected',
        ];

        $events = AuditEvent::query()
            ->whereBelongsTo($project)
            ->whereIn('event_type', $eventTypes)
            ->where('occurred_at', '>=', $this->since())
            ->latest('id')
            ->limit($this->scanLimit())
            ->get();

        foreach ($events as $event) {
            $payload = $this->arrayAttribute($event, 'payload');
            $metadata = $this->auditMetadata($event->event_type, $payload);

            if ($metadata === null) {
                continue;
            }

            $items[] = [
                'fingerprint_payload' => [
                    'source_kind' => $metadata['source_kind'],
                    'failure_code' => $event->event_type,
                    'affected_role' => $metadata['affected_role'],
                    'affected_area' => $metadata['affected_area'],
                ],
                'source_kind' => $metadata['source_kind'],
                'failure_code' => $event->event_type,
                'affected_role' => $metadata['affected_role'],
                'affected_area' => $metadata['affected_area'],
                'target_type' => $metadata['target_type'],
                'target_skill_slug' => $metadata['target_skill_slug'],
                'proposed_change' => $metadata['proposed_change'],
                'reference' => [
                    'source_type' => 'audit_event',
                    'source_id' => $event->id,
                    'audit_event_id' => $event->id,
                    'task_id' => $event->task_id,
                ],
                'occurred_at' => $this->dateValue($event, 'occurred_at'),
            ];
        }

        return $items;
    }

    /**
     * Normalize durable non-transient RecoveryIncident root causes into recurring evidence.
     *
     * @return list<array{
     *     fingerprint_payload: array<string, mixed>,
     *     source_kind: string,
     *     failure_code: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string,
     *     reference: array<string, mixed>,
     *     occurred_at: string
     * }>
     */
    private function recoveryIncidentItems(Project $project): array
    {
        $items = [];

        $incidents = RecoveryIncident::query()
            ->whereBelongsTo($project)
            ->whereNotNull('root_cause_category')
            ->whereIn('status', ['recovered', 'escalated', 'failed'])
            ->where('updated_at', '>=', $this->since())
            ->latest('id')
            ->limit($this->scanLimit())
            ->get();

        foreach ($incidents as $incident) {
            $category = $this->normalizedIdentifier($incident->root_cause_category);

            if ($category === '' || $category === 'transient_harness_failure') {
                continue;
            }

            $failureCode = 'recovery:'.$category;
            $target = $this->recoveryTarget($category);
            $area = $this->areaFromFiles(
                $this->stringListAttribute($incident, 'changed_files'),
            ) ?? 'recovery';

            $items[] = [
                'fingerprint_payload' => [
                    'source_kind' => 'recovery_incident',
                    'failure_code' => $failureCode,
                    'affected_role' => $target['affected_role'],
                    'affected_area' => $area,
                ],
                'source_kind' => 'recovery_incident',
                'failure_code' => $failureCode,
                'affected_role' => $target['affected_role'],
                'affected_area' => $area,
                'target_type' => $target['target_type'],
                'target_skill_slug' => $target['target_skill_slug'],
                'proposed_change' => $target['proposed_change'],
                'reference' => [
                    'source_type' => 'recovery_incident',
                    'source_id' => $incident->id,
                    'recovery_incident_id' => $incident->id,
                    'task_id' => $incident->task_id,
                    'agent_run_id' => $incident->source_agent_run_id,
                ],
                'occurred_at' => $this->dateValue($incident, 'updated_at'),
            ];
        }

        return $items;
    }

    /**
     * Add one item to its deterministic fingerprint group and preserve its occurrence policy.
     *
     * @param array<string, array{
     *     source_kind: string,
     *     failure_code: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string,
     *     references: list<array<string, mixed>>,
     *     occurred_at: list<string>,
     *     minimum_occurrences: int
     * }> $groups
     * @param array{
     *     fingerprint_payload: array<string, mixed>,
     *     source_kind: string,
     *     failure_code: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string,
     *     reference: array<string, mixed>,
     *     occurred_at: string,
     *     minimum_occurrences?: int
     * } $item
     */
    private function addToGroup(array &$groups, array $item): void
    {
        $fingerprint = $this->fingerprint($item['fingerprint_payload']);
        $minimumOccurrences = max(
            1,
            (int) ($item['minimum_occurrences'] ?? $this->occurrenceThreshold()),
        );

        $groups[$fingerprint] ??= [
            'source_kind' => $item['source_kind'],
            'failure_code' => $item['failure_code'],
            'affected_role' => $item['affected_role'],
            'affected_area' => $item['affected_area'],
            'target_type' => $item['target_type'],
            'target_skill_slug' => $item['target_skill_slug'],
            'proposed_change' => $item['proposed_change'],
            'references' => [],
            'occurred_at' => [],
            'minimum_occurrences' => $minimumOccurrences,
        ];

        $groups[$fingerprint]['references'][] = $item['reference'];
        $groups[$fingerprint]['occurred_at'][] = $item['occurred_at'];
        $groups[$fingerprint]['minimum_occurrences'] = min(
            $groups[$fingerprint]['minimum_occurrences'],
            $minimumOccurrences,
        );
    }

    /**
     * Create, update, or reopen one existing KnowledgeImprovementCandidate fingerprint atomically.
     *
     * @param array{
     *     source_kind: string,
     *     failure_code: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string,
     *     references: list<array<string, mixed>>,
     *     occurred_at: list<string>,
     *     minimum_occurrences: int
     * } $group
     */
    private function persistGroup(Project $project, string $fingerprint, array $group): bool
    {
        $references = $this->uniqueReferences($group['references']);
        $occurrenceCount = count($references);
        $evidenceHash = $this->fingerprint($references);
        $dates = $group['occurred_at'];
        sort($dates, SORT_STRING);
        $firstSeenAt = $dates[0] ?? now()->toIso8601String();
        $lastSeenAt = $dates[count($dates) - 1] ?? $firstSeenAt;
        $targetSkill = $this->targetSkill(
            $project,
            $group['target_skill_slug'],
            $group['affected_role'],
        );
        $targetType = $group['target_type'];

        if ($targetType === KnowledgeImprovementTarget::Skill && $targetSkill === null) {
            $targetType = KnowledgeImprovementTarget::Documentation;
        }

        return DB::transaction(function () use (
            $project,
            $fingerprint,
            $group,
            $references,
            $occurrenceCount,
            $evidenceHash,
            $firstSeenAt,
            $lastSeenAt,
            $targetSkill,
            $targetType,
        ): bool {
            $candidate = KnowledgeImprovementCandidate::query()->firstOrCreate(
                [
                    'project_id' => $project->id,
                    'fingerprint' => $fingerprint,
                ],
                [
                    'target_skill_id' => $targetSkill?->id,
                    'source_kind' => $group['source_kind'],
                    'failure_code' => $group['failure_code'],
                    'affected_role' => $group['affected_role'],
                    'affected_area' => $group['affected_area'],
                    'status' => KnowledgeImprovementCandidateStatus::Pending,
                    'target_type' => $targetType,
                    'evidence_summary' => $this->evidenceSummary($group, $occurrenceCount),
                    'proposed_change' => $group['proposed_change'],
                    'evidence' => array_slice($references, -self::MaxStoredEvidenceReferences),
                    'occurrence_count' => $occurrenceCount,
                    'evidence_hash' => $evidenceHash,
                    'first_seen_at' => $firstSeenAt,
                    'last_seen_at' => $lastSeenAt,
                ],
            );
            $wasCreated = $candidate->wasRecentlyCreated;

            $candidate = KnowledgeImprovementCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($wasCreated) {
                $this->audit->record('knowledge_improvement_candidate.created', [
                    'candidate_id' => $candidate->id,
                    'fingerprint' => $fingerprint,
                    'source_kind' => $group['source_kind'],
                    'failure_code' => $group['failure_code'],
                    'occurrence_count' => $occurrenceCount,
                    'target_type' => $targetType->value,
                    'target_skill_id' => $targetSkill?->id,
                ], $project);

                return true;
            }

            if ($candidate->evidence_hash === $evidenceHash) {
                return false;
            }

            $status = KnowledgeImprovementCandidateStatus::from($candidate->getRawOriginal('status'));
            $shouldReopen = $status !== KnowledgeImprovementCandidateStatus::Pending
                && $candidate->reopen_after_occurrence !== null
                && $occurrenceCount >= $candidate->reopen_after_occurrence;

            $candidate->update([
                'target_skill_id' => $targetSkill?->id,
                'target_type' => $targetType,
                'evidence_summary' => $this->evidenceSummary($group, $occurrenceCount),
                'proposed_change' => $group['proposed_change'],
                'evidence' => array_slice($references, -self::MaxStoredEvidenceReferences),
                'occurrence_count' => max($candidate->occurrence_count, $occurrenceCount),
                'evidence_hash' => $evidenceHash,
                'last_seen_at' => $lastSeenAt,
                ...($shouldReopen ? [
                    'status' => KnowledgeImprovementCandidateStatus::Pending,
                    'decided_by_user_id' => null,
                    'decided_at' => null,
                    'reopen_after_occurrence' => null,
                ] : []),
            ]);

            $this->audit->record(
                $shouldReopen ? 'knowledge_improvement_candidate.reopened' : 'knowledge_improvement_candidate.evidence_updated',
                [
                    'candidate_id' => $candidate->id,
                    'fingerprint' => $fingerprint,
                    'occurrence_count' => $candidate->refresh()->occurrence_count,
                    'evidence_hash' => $evidenceHash,
                ],
                $project,
            );

            return true;
        }, attempts: 3);
    }

    /**
     * Reduce Reviewer prose to one bounded deterministic failure family.
     */
    private function reviewFamily(ReviewFinding $finding): string
    {
        $text = Str::lower(Str::squish(implode(' ', [
            (string) $finding->location,
            (string) $finding->expected_implementation,
            (string) $finding->why_incorrect,
            (string) $finding->required_fix,
            (string) $finding->verification_requirement,
        ])));

        foreach (self::ReviewFamilies as $family => $needles) {
            foreach ($needles as $needle) {
                if (Str::contains($text, $needle)) {
                    return $family;
                }
            }
        }

        return 'task_contract';
    }

    /**
     * Map a normalized Reviewer family to its preferred existing same-project Skill slug.
     */
    private function reviewTargetSkillSlug(string $family): string
    {
        return match ($family) {
            'git_isolation' => 'coder-git-change-isolation',
            'validation_regression' => 'coder-regression-test-engineering',
            'context_determinism' => 'coder-repository-reconnaissance',
            default => 'coder-minimal-production-ready-implementation',
        };
    }

    /**
     * Return failed deterministic checks while excluding operational/provider failures.
     *
     * @param  array<string, mixed>  $validation
     * @return list<string>
     */
    private function failedDeterministicChecks(array $validation): array
    {
        $checks = is_array($validation['checks'] ?? null) ? $validation['checks'] : [];
        $failed = [];

        foreach ($checks as $name => $passed) {
            if (! is_string($name) || $passed !== false || in_array($name, self::OperationalValidationChecks, true)) {
                continue;
            }

            $failed[] = $name;
        }

        sort($failed, SORT_STRING);

        return array_values(array_unique($failed));
    }

    /**
     * Map deterministic validation families to their preferred existing same-project Skill slug.
     *
     * @param  list<string>  $checks
     */
    private function validationTargetSkillSlug(array $checks): string
    {
        foreach ($checks as $check) {
            if (Str::startsWith($check, 'git_')) {
                return 'coder-git-change-isolation';
            }
        }

        foreach ($checks as $check) {
            if (Str::contains($check, ['test', 'pest', 'phpunit'])) {
                return 'coder-regression-test-engineering';
            }
        }

        return 'coder-deterministic-validation';
    }

    /**
     * Resolve the most specific deterministic affected area from failed evidence and changed files.
     *
     * @param  array<string, mixed>  $validation
     * @param  array<int, string>  $changedFiles
     */
    private function validationArea(array $validation, array $changedFiles): string
    {
        $files = [];
        $this->collectFailedEvidenceFiles(is_array($validation['evidence'] ?? null) ? $validation['evidence'] : [], $files);
        $files = [...$files, ...$changedFiles];

        return $this->areaFromFiles($files) ?? 'validation';
    }

    /**
     * Recursively collect files only from validation evidence explicitly marked failed.
     *
     * @param  array<mixed>  $value
     * @param  array<int, string>  $files
     */
    private function collectFailedEvidenceFiles(array $value, array &$files): void
    {
        if (($value['passed'] ?? null) === false && is_array($value['files'] ?? null)) {
            foreach ($value['files'] as $file) {
                if (is_string($file) && $file !== '') {
                    $files[] = $file;
                }
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested)) {
                $this->collectFailedEvidenceFiles($nested, $files);
            }
        }
    }

    /**
     * Map known blocking audit events to bounded candidate metadata.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     source_kind: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string
     * }|null
     */
    private function auditMetadata(string $eventType, array $payload): ?array
    {
        return match ($eventType) {
            'task.blocked_dirty_repository' => [
                'source_kind' => 'repository_block',
                'affected_role' => 'coder',
                'affected_area' => 'repository',
                'target_type' => KnowledgeImprovementTarget::Skill,
                'target_skill_slug' => 'coder-git-change-isolation',
                'proposed_change' => 'Before a new Coder attempt starts, verify the managed repository is clean or explicitly attributable to same-task recovery; never stash, reset, clean, discard, or silently absorb unrelated changes to unblock a task.',
            ],
            'task.blocked_unsafe_path' => [
                'source_kind' => 'security_block',
                'affected_role' => null,
                'affected_area' => 'workspace',
                'target_type' => KnowledgeImprovementTarget::Documentation,
                'target_skill_slug' => null,
                'proposed_change' => 'Document the managed-project path registration and remediation checks that prevent path traversal, symlink escape, or unsafe workspace placement before a blocked task is explicitly requeued.',
            ],
            'task.blocked_agent_misconfigured' => [
                'source_kind' => 'configuration_block',
                'affected_role' => $this->normalizedRole($payload['role'] ?? null),
                'affected_area' => 'agent_configuration',
                'target_type' => KnowledgeImprovementTarget::Documentation,
                'target_skill_slug' => null,
                'proposed_change' => 'Document the Agent binding and harness-configuration preflight/remediation path; an already configured but broken binding must fail closed and must never silently fall back to another harness.',
            ],
            'task.contract_drift_detected' => [
                'source_kind' => 'workflow_contract',
                'affected_role' => $this->normalizedRole($payload['operation'] ?? null),
                'affected_area' => 'task_contract',
                'target_type' => KnowledgeImprovementTarget::Rule,
                'target_skill_slug' => null,
                'proposed_change' => 'Keep task-contract drift fail-closed: require explicit requeue/replan/rebase when authoritative task inputs change instead of letting a retry adopt mutated inputs under the original attempt evidence.',
            ],
            default => null,
        };
    }

    /**
     * Map a normalized RecoveryIncident root-cause category to a bounded proposal target.
     *
     * @return array{
     *     affected_role: ?string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string
     * }
     */
    private function recoveryTarget(string $category): array
    {
        return match ($category) {
            'application_defect', 'managed_project_defect' => [
                'affected_role' => 'coder',
                'target_type' => KnowledgeImprovementTarget::Skill,
                'target_skill_slug' => 'coder-minimal-production-ready-implementation',
                'proposed_change' => 'Recurring recovery evidence shows an implementation defect escaped the normal Coder gates. Before completing similar work, verify the root-cause behavior against the task contract and add the focused regression evidence needed to prevent the same recovery class.',
            ],
            'validation_failure' => [
                'affected_role' => 'coder',
                'target_type' => KnowledgeImprovementTarget::Skill,
                'target_skill_slug' => 'coder-deterministic-validation',
                'proposed_change' => 'Recurring recovery evidence points to deterministic validation failure. Treat the relevant validation gate as required completion evidence and rerun it after the corrective change before returning work for review.',
            ],
            'orchestration_defect' => [
                'affected_role' => null,
                'target_type' => KnowledgeImprovementTarget::Rule,
                'target_skill_slug' => null,
                'proposed_change' => 'Capture the recurring orchestration invariant as a path-scoped `.ai/rules/**` guardrail and cover it with deterministic regression tests through the normal AIOS Task/Git/Reviewer lifecycle.',
            ],
            default => [
                'affected_role' => null,
                'target_type' => KnowledgeImprovementTarget::Documentation,
                'target_skill_slug' => null,
                'proposed_change' => "Document the recurring recovery category [{$category}], its deterministic detection evidence, safe operator remediation, and the exact condition required before normal workflow execution may resume.",
            ],
        };
    }

    /**
     * Resolve only enabled same-project Skill guidance that is applicable to the affected role.
     */
    private function targetSkill(
        Project $project,
        ?string $slug,
        ?string $affectedRole = null,
    ): ?Skill {
        if ($slug === null) {
            return null;
        }

        $skill = $project->skills()
            ->enabled()
            ->where('slug', $slug)
            ->first();

        if (! $skill instanceof Skill || $affectedRole === null) {
            return $skill;
        }

        $roles = $skill->getAttribute('applicable_roles');

        if (! is_array($roles)) {
            return null;
        }

        return $roles === [] || in_array($affectedRole, $roles, true)
            ? $skill
            : null;
    }

    /**
     * Deduplicate evidence using the stable source type and source identity pair.
     *
     * @param  list<array<string, mixed>>  $references
     * @return list<array<string, mixed>>
     */
    private function uniqueReferences(array $references): array
    {
        $unique = [];

        foreach ($references as $reference) {
            $key = (string) ($reference['source_type'] ?? 'unknown').':'.(string) ($reference['source_id'] ?? 'unknown');
            $unique[$key] = $reference;
        }

        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * Produce concise evidence text that distinguishes immediate point defects from recurring patterns.
     *
     * @param array{
     *     source_kind: string,
     *     failure_code: string,
     *     affected_role: ?string,
     *     affected_area: string,
     *     target_type: KnowledgeImprovementTarget,
     *     target_skill_slug: ?string,
     *     proposed_change: string,
     *     references: list<array<string, mixed>>,
     *     occurred_at: list<string>,
     *     minimum_occurrences: int
     * } $group
     */
    private function evidenceSummary(array $group, int $occurrenceCount): string
    {
        $role = $group['affected_role'] ?? 'system';

        if ($group['minimum_occurrences'] === 1) {
            return "Deterministic {$group['source_kind']} finding matched [{$group['failure_code']}] in [{$group['affected_area']}] for [{$role}] with {$occurrenceCount} durable evidence reference(s).";
        }

        return "{$occurrenceCount} recurring {$group['source_kind']} occurrences matched [{$group['failure_code']}] in [{$group['affected_area']}] for [{$role}].";
    }

    /**
     * Normalize an arbitrary finding location into a bounded repository/subsystem area.
     */
    private function areaFromLocation(?string $location): string
    {
        $location = Str::squish((string) $location);

        if ($location === '') {
            return 'unspecified';
        }

        if (preg_match('#(?:^|\s)((?:app|resources|routes|tests|database|config|bootstrap)/[^\s:,]+)#i', $location, $matches) === 1) {
            return $this->pathArea($matches[1]);
        }

        $withoutLines = preg_replace('/:\d+(?:-\d+)?\b/', '', $location) ?? $location;

        return Str::limit(Str::lower($withoutLines), 160, '');
    }

    /**
     * Resolve the first stable affected area from a list of changed/evidence paths.
     *
     * @param  array<int, mixed>  $files
     */
    private function areaFromFiles(array $files): ?string
    {
        $areas = [];

        foreach ($files as $file) {
            if (is_string($file) && $file !== '') {
                $areas[] = $this->pathArea($file);
            }
        }

        $areas = array_values(array_unique($areas));
        sort($areas, SORT_STRING);

        return $areas[0] ?? null;
    }

    /**
     * Collapse a repository path to a bounded subsystem-level area.
     */
    private function pathArea(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('/:\d+(?:-\d+)?$/', '', $path) ?? $path;
        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return 'unspecified';
        }

        $depth = match ($segments[0]) {
            'app', 'tests', 'database', 'resources' => 2,
            default => 1,
        };

        return implode('/', array_slice($segments, 0, $depth));
    }

    /**
     * Convert a bounded string value into a stable snake-case identifier.
     */
    private function normalizedIdentifier(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return Str::snake(Str::lower(Str::squish($value)));
    }

    /**
     * Return only supported workflow/recovery role identifiers from arbitrary metadata.
     */
    private function normalizedRole(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $role = $this->normalizedIdentifier($value);

        return in_array($role, ['project_manager', 'coder', 'reviewer', 'recovery_engineer'], true)
            ? $role
            : null;
    }

    /**
     * Safely read a cast Eloquent array attribute.
     *
     * @return array<string, mixed>
     */
    private function arrayAttribute(Model $model, string $attribute): array
    {
        $value = $model->getAttribute($attribute);

        return is_array($value) ? $value : [];
    }

    /**
     * Safely read only string entries from a cast Eloquent array attribute.
     *
     * @return list<string>
     */
    private function stringListAttribute(Model $model, string $attribute): array
    {
        $value = $model->getAttribute($attribute);

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Serialize a model date attribute into deterministic ISO-8601 evidence.
     */
    private function dateValue(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        return $value instanceof CarbonInterface
            ? $value->toIso8601String()
            : now()->toIso8601String();
    }

    /**
     * Hash canonical bounded evidence into a stable SHA-256 fingerprint.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $payload
     */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    /**
     * Recursively sort associative payload keys while preserving list ordering.
     */
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

    /**
     * Return the configured minimum recurrence threshold, never below the established floor of three.
     */
    private function occurrenceThreshold(): int
    {
        return max(3, (int) config('aios.knowledge_improvement_occurrence_threshold', 3));
    }

    /**
     * Return the bounded evidence query limit shared by all recurring detectors.
     */
    private function scanLimit(): int
    {
        return max(50, min(5000, (int) config('aios.knowledge_improvement_scan_limit', 500)));
    }

    /**
     * Return the oldest timestamp eligible for recurring evidence collection.
     */
    private function since(): CarbonInterface
    {
        $days = max(1, min(3650, (int) config('aios.knowledge_improvement_lookback_days', 180)));

        return now()->subDays($days);
    }
}
