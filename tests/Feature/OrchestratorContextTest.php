<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Phase;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\ReviewStatus;
use App\Services\AgentContextAssembler;
use App\Services\ContextBudgetGuard;
use App\Services\ContextBudgetPolicy;
use App\Services\ContextCostEstimator;
use App\Services\OrchestratorContextCapsuleFactory;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;

/**
 * Create a persisted project with a distinctive repository path that must never enter the capsule.
 */
function p5003Project(
    string $name,
): Project {
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir()
            .'/P5003-REPOSITORY-PATH-SENTINEL-'
            .fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one deterministic phase for P5-003 task fixtures.
 */
function p5003Phase(
    Project $project,
    int $position = 1,
): Phase {
    return Phase::create([
        'project_id' => $project->id,
        'position' => $position,
        'title' => 'P5-003 phase '.$position,
        'objective' => 'Exercise bounded Orchestrator evidence.',
    ]);
}

/**
 * Create a task containing excluded execution-oriented context sentinels.
 */
function p5003Task(
    Project $project,
    Phase $phase,
    string $key,
    int $position,
    TaskStatus $status = TaskStatus::Queued,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => $key,
        'position' => $position,
        'title' => 'Bounded Orchestrator evidence '.$key,
        'objective' => 'Evaluate durable AIOS evidence without gaining mutation authority.',
        'work_type' => TaskWorkType::Feature,
        'complexity' => TaskComplexity::Medium,
        'acceptance_criteria' => [
            'Only allowlisted durable evidence is assembled.',
        ],
        'scope' => [
            'Do not traverse the repository.',
        ],
        'constraints' => [
            'Remain advisory only.',
        ],
        'relevant_paths' => [
            'P5003-REPOSITORY-CONTENT-SENTINEL.php',
        ],
        'verification_commands' => [
            'php artisan test tests/Feature/OrchestratorContextTest.php',
        ],
        'implementation_prompt' => 'P5003-FULL-IMPLEMENTATION-PROMPT-SENTINEL',
        'context_capsule' => [
            'obsidian_notes' => [
                'Secrets/P5003-OBSIDIAN-SENTINEL.md',
            ],
            'raw' => 'P5003-OBSIDIAN-CONTENT-SENTINEL',
        ],
        'status' => $status,
    ]);
}

/**
 * Resolve the singleton global Orchestrator provisioned by the existing P5-001 infrastructure.
 */
function p5003Orchestrator(): Agent
{
    return Agent::query()
        ->whereNull('project_id')
        ->where(
            'role',
            AgentRole::Orchestrator,
        )
        ->sole();
}

/**
 * Create a completed Coder run carrying immutable configuration and Context Budget evidence.
 */
function p5003CoderRun(
    Project $project,
    Task $task,
): AgentRun {
    $agent = Agent::query()
        ->where(
            'project_id',
            $project->id,
        )
        ->where(
            'role',
            AgentRole::Coder,
        )
        ->first()
        ?? Agent::factory()
            ->for($project)
            ->create([
                'role' => AgentRole::Coder,
            ]);

    $context = app(
        AgentContextAssembler::class,
    )->assemble(
        $agent,
        AgentRole::Coder,
        [
            'task_key' => $task->key,
            'objective' => $task->objective,
        ],
    );

    $promptHash = hash(
        'sha256',
        'P5-003 historical prompt',
    );

    return AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_id' => $agent->id,
        'role' => AgentRole::Coder,
        'harness' => $agent->getRawOriginal(
            'harness',
        ),
        'status' => AgentRunStatus::Completed,
        'attempt_number' => 1,
        'prompt_hash' => $promptHash,

        'result' => [
            'provider_conversation' => 'P5003-RESULT-TRANSCRIPT-SENTINEL',
        ],

        'configuration_snapshot' => $context->configurationSnapshot(),

        'context_schema_version' => $context->contextSchemaVersion,

        'context_cost_estimate' => $context->contextCostEstimate,

        'context_cost_schema_version' => $context->contextCostSchemaVersion,

        'context_budget_snapshot' => [
            'schema_version' => ContextBudgetPolicy::SchemaVersion,
            'policy_version' => ContextBudgetPolicy::PolicyVersion,
            'capacity_source' => 'test:model',
            'capacity_source_version' => 1,
            'resolved_capacity_tokens' => 200000,
            'role' => AgentRole::Coder->value,
            'target_percent' => 70,
            'warning_percent' => 75,
            'hard_ceiling_percent' => 80,
            'budget_tokens' => 140000,
            'original_estimated_tokens' => 1200,
            'final_estimated_tokens' => 1100,
            'required_estimated_tokens' => 700,

            'source_contributions' => [
                'original' => [
                    'older_history' => [
                        'characters' => 1000,
                        'estimated_tokens' => 250,
                    ],
                ],
                'final' => [
                    'older_history' => [
                        'characters' => 600,
                        'estimated_tokens' => 150,
                    ],
                ],
            ],

            'included_sources' => [
                'task_required_core',
                'older_history',
            ],

            'reduced_sources' => [
                'older_history',
            ],

            'excluded_sources' => [],

            'reductions' => [
                [
                    'source' => 'older_history',
                    'before_estimated_tokens' => 250,
                    'after_estimated_tokens' => 150,
                    'quota_tokens' => 100,
                    'method' => 'fixed_quota_safe_boundary_v1',
                ],
            ],

            'decision' => 'reduced',
            'original_context_hash' => $context->hash,
            'final_context_hash' => $context->hash,
            'original_prompt_hash' => $promptHash,
            'final_prompt_hash' => $promptHash,
        ],

        'context_budget_schema_version' => ContextBudgetPolicy::SchemaVersion,

        'live_output' => 'P5003-LIVE-TRANSCRIPT-SENTINEL',

        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'exit_code' => 0,
    ]);
}

/**
 * Create failed validation and no-progress evidence for the scoped task.
 */
function p5003FailedAttempt(
    Task $task,
    array $changedFiles = [
        'z.php',
        'a.php',
    ],
): TaskAttempt {
    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => str_repeat(
            'a',
            40,
        ),
        'head_sha' => str_repeat(
            'b',
            40,
        ),
        'status' => 'failed',

        'validation_results' => [
            'passed' => false,

            'checks' => [
                'tests' => true,
                'phpstan' => false,
            ],

            'evidence' => [
                'phpstan' => [
                    'passed' => false,
                    'name' => 'phpstan',
                    'summary' => 'Static analysis failed for the bounded fixture.',
                    'files' => [
                        'z.php',
                        'a.php',
                    ],
                ],
            ],

            'no_progress' => [
                'detected' => true,
                'failure_fingerprint' => str_repeat(
                    'c',
                    64,
                ),
                'repository_fingerprint' => str_repeat(
                    'd',
                    64,
                ),
                'consecutive_identical_failures' => 2,
                'consecutive_repeat_count' => 1,
                'threshold' => 1,
            ],
        ],

        'changed_files' => $changedFiles,

        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);
}

/**
 * Encode the capsule for negative-content assertions.
 */
function p5003Json(
    array $value,
): string {
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
    );
}

test(
    'unchanged durable evidence produces the same capsule hash and explicit retrieval manifest',
    function (): void {
        $project = p5003Project(
            'P5-003 deterministic capsule',
        );

        $phase = p5003Phase(
            $project,
        );

        $dependency = p5003Task(
            $project,
            $phase,
            'P5-002',
            1,
            TaskStatus::Done,
        );

        $task = p5003Task(
            $project,
            $phase,
            'P5-003',
            2,
        );

        $task->dependencies()->attach(
            $dependency->id,
        );

        p5003CoderRun(
            $project,
            $task,
        );

        AuditEvent::create([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'event_type' => 'unrelated.full.audit.event',
            'payload' => [
                'secret' => 'P5003-FULL-AUDIT-HISTORY-SENTINEL',
            ],
            'occurred_at' => now(),
        ]);

        $factory = app(
            OrchestratorContextCapsuleFactory::class,
        );

        $first = $factory->make(
            $project,
            $task,
        );

        $second = $factory->make(
            $project,
            $task,
        );

        $sources = collect(
            $first['retrieval_manifest']['sources'],
        )->keyBy('family');

        $encoded = p5003Json(
            $first,
        );

        expect($first)
            ->toBe($second)
            ->and(
                $first['capsule_hash'],
            )
            ->toMatch(
                '/\A[a-f0-9]{64}\z/',
            )
            ->and(
                $first['schema_version'],
            )
            ->toBe(
                OrchestratorContextCapsuleFactory::SchemaVersion,
            )
            ->and(
                $sources['current_agent_configuration']['state'],
            )
            ->toBe('included')
            ->and(
                $sources['agent_runs']['state'],
            )
            ->toBe('included')
            ->and(
                $sources['obsidian_project_knowledge']['state'],
            )
            ->toBe('excluded_by_contract')
            ->and(
                $sources['repository_contents']['state'],
            )
            ->toBe('excluded_by_contract')
            ->and(
                $sources['full_audit_history']['state'],
            )
            ->toBe('excluded_by_contract')
            ->and(
                $sources['provider_transcripts']['state'],
            )
            ->toBe('excluded_by_contract')
            ->and(
                $sources['operator_requester_history']['state'],
            )
            ->toBe('excluded_by_contract')
            ->and($encoded)
            ->not->toContain(
                $project->path,
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-REPOSITORY-CONTENT-SENTINEL.php',
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-FULL-IMPLEMENTATION-PROMPT-SENTINEL',
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-OBSIDIAN-SENTINEL',
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-OBSIDIAN-CONTENT-SENTINEL',
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-LIVE-TRANSCRIPT-SENTINEL',
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-RESULT-TRANSCRIPT-SENTINEL',
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-FULL-AUDIT-HISTORY-SENTINEL',
            );
    },
);

test(
    'a meaningful durable evidence change changes the capsule hash',
    function (): void {
        $project = p5003Project(
            'P5-003 durable change',
        );

        $phase = p5003Phase(
            $project,
        );

        $task = p5003Task(
            $project,
            $phase,
            'P5-003',
            1,
        );

        $factory = app(
            OrchestratorContextCapsuleFactory::class,
        );

        $before = $factory->make(
            $project,
            $task,
        );

        $task->update([
            'status' => TaskStatus::Blocked,
        ]);

        $after = $factory->make(
            $project,
            $task->refresh(),
        );

        expect(
            $before['capsule_hash'],
        )
            ->not->toBe(
                $after['capsule_hash'],
            )
            ->and(
                $after['workflow_state']['task_status'],
            )
            ->toBe(
                TaskStatus::Blocked->value,
            );
    },
);

test(
    'set-like persisted evidence is normalized so storage ordering does not change the hash',
    function (): void {
        $project = p5003Project(
            'P5-003 ordering',
        );

        $phase = p5003Phase(
            $project,
        );

        $task = p5003Task(
            $project,
            $phase,
            'P5-003',
            1,
            TaskStatus::Failed,
        );

        $attempt = p5003FailedAttempt(
            $task,
            [
                'z.php',
                'a.php',
            ],
        );

        $factory = app(
            OrchestratorContextCapsuleFactory::class,
        );

        $first = $factory->make(
            $project,
            $task,
        );

        $validation = $attempt->validation_results;

        $validation['evidence']['phpstan']['files'] = [
            'a.php',
            'z.php',
        ];

        $attempt->update([
            'changed_files' => [
                'a.php',
                'z.php',
            ],
            'validation_results' => $validation,
        ]);

        $second = $factory->make(
            $project,
            $task->refresh(),
        );

        expect(
            $first['previous_attempt']['changed_files'],
        )
            ->toBe([
                'a.php',
                'z.php',
            ])
            ->and(
                $first['validation_evidence']['failed_evidence']['phpstan']['files'],
            )
            ->toBe([
                'a.php',
                'z.php',
            ])
            ->and(
                $first['capsule_hash'],
            )
            ->toBe(
                $second['capsule_hash'],
            );
    },
);

test(
    'allowlisted historical collections are deterministically bounded',
    function (): void {
        $project = p5003Project(
            'P5-003 collection bounds',
        );

        $phase = p5003Phase(
            $project,
        );

        $task = p5003Task(
            $project,
            $phase,
            'P5-003',
            1,
            TaskStatus::ChangesRequired,
        );

        $attempt = p5003FailedAttempt(
            $task,
        );

        $review = Review::create([
            'task_id' => $task->id,
            'task_attempt_id' => $attempt->id,
            'status' => ReviewStatus::ChangesRequired,
            'summary' => 'Bound review findings.',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        foreach (
            range(1, 10) as $index
        ) {
            ReviewFinding::create([
                'review_id' => $review->id,
                'severity' => 'major',
                'location' => 'app/Services/Bounded.php:'.$index,
                'current_implementation' => 'Current '.$index,
                'expected_implementation' => 'Expected '.$index,
                'why_incorrect' => 'Reason '.$index,
                'required_fix' => 'Fix '.$index,
                'verification_requirement' => 'Verify '.$index,
                'implementation_fix_context' => 'Context '.$index,
            ]);
        }

        foreach (
            range(1, 15) as $index
        ) {
            $run = p5003CoderRun(
                $project,
                $task,
            );

            $run->update([
                'attempt_number' => $index,
            ]);
        }

        $capsule = app(
            OrchestratorContextCapsuleFactory::class,
        )->make(
            $project,
            $task,
        );

        expect(
            $capsule['review_findings'],
        )
            ->toHaveCount(8)
            ->and(
                $capsule['older_history']['agent_runs'],
            )
            ->toHaveCount(12)
            ->and(
                collect(
                    $capsule['review_findings'],
                )->pluck('id')->all(),
            )
            ->toBe(
                collect(
                    $capsule['review_findings'],
                )
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all(),
            )
            ->and(
                collect(
                    $capsule['older_history']['agent_runs'],
                )->pluck('id')->all(),
            )
            ->toBe(
                collect(
                    $capsule['older_history']['agent_runs'],
                )
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all(),
            );
    },
);

test(
    'project and Task RecoveryIncident boundaries fail closed before evidence assembly',
    function (): void {
        $project = p5003Project(
            'P5-003 source project',
        );

        $phase = p5003Phase(
            $project,
        );

        $task = p5003Task(
            $project,
            $phase,
            'P5-003-A',
            1,
        );

        $otherTask = p5003Task(
            $project,
            $phase,
            'P5-003-B',
            2,
        );

        $foreignProject = p5003Project(
            'P5-003 foreign project',
        );

        $foreignPhase = p5003Phase(
            $foreignProject,
        );

        $foreignTask = p5003Task(
            $foreignProject,
            $foreignPhase,
            'FOREIGN-001',
            1,
        );

        $foreignIncident = RecoveryIncident::create([
            'project_id' => $foreignProject->id,
            'task_id' => $foreignTask->id,
            'failure_type' => 'task_blocked',
            'status' => RecoveryIncidentStatus::Detected,
            'detected_at' => now(),
        ]);

        $mismatchedIncident = RecoveryIncident::create([
            'project_id' => $project->id,
            'task_id' => $otherTask->id,
            'failure_type' => 'task_blocked',
            'status' => RecoveryIncidentStatus::Detected,
            'detected_at' => now(),
        ]);

        $factory = app(
            OrchestratorContextCapsuleFactory::class,
        );

        expect(
            fn () => $factory->make(
                $project,
                $foreignTask,
            ),
        )
            ->toThrow(
                LogicException::class,
                'cannot cross the requested Project boundary',
            )
            ->and(
                fn () => $factory->make(
                    $project,
                    recoveryIncident: $foreignIncident,
                ),
            )
            ->toThrow(
                LogicException::class,
                'cannot cross the requested Project boundary',
            )
            ->and(
                fn () => $factory->make(
                    $project,
                    $task,
                    $mismatchedIncident,
                ),
            )
            ->toThrow(
                LogicException::class,
                'Orchestrator Task and RecoveryIncident scope must refer to the same Task.',
            );
    },
);

test(
    'the capsule represents bounded run validation review budget retry recovery knowledge and workflow evidence',
    function (): void {
        $project = p5003Project(
            'P5-003 evidence families',
        );

        $phase = p5003Phase(
            $project,
        );

        $task = p5003Task(
            $project,
            $phase,
            'P5-003',
            1,
            TaskStatus::ChangesRequired,
        );

        $attempt = p5003FailedAttempt(
            $task,
        );

        $run = p5003CoderRun(
            $project,
            $task,
        );

        $review = Review::create([
            'task_id' => $task->id,
            'task_attempt_id' => $attempt->id,
            'status' => ReviewStatus::ChangesRequired,
            'summary' => 'The implementation still violates the bounded evidence contract.',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $finding = ReviewFinding::create([
            'review_id' => $review->id,
            'severity' => 'major',
            'location' => 'app/Services/Example.php:10',
            'current_implementation' => 'The service reads uncontrolled context.',
            'expected_implementation' => 'Only allowlisted durable evidence is read.',
            'why_incorrect' => 'Uncontrolled context would break deterministic scope.',
            'required_fix' => 'Use the typed project and Task scope only.',
            'verification_requirement' => 'Run OrchestratorContextTest.',
            'implementation_fix_context' => 'Preserve advisory-only Orchestrator authority.',
        ]);

        $incident = RecoveryIncident::create([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'source_agent_run_id' => $run->id,
            'failure_type' => 'implementation_regression',
            'status' => RecoveryIncidentStatus::Escalated,
            'detected_at' => now()->subMinute(),
            'root_cause' => 'A bounded deterministic failure was identified.',
            'root_cause_category' => 'application_defect',
            'recoverable' => false,
            'attempt_count' => 1,
            'validation_evidence' => [
                'passed' => false,
                'checks' => [
                    'regression' => false,
                ],
            ],
            'changed_files' => [
                'z.php',
                'a.php',
            ],
            'escalation_reason' => 'Operator judgment is required.',
        ]);

        $candidate = KnowledgeImprovementCandidate::factory()
            ->for($project)
            ->create([
                'status' => KnowledgeImprovementCandidateStatus::Pending,
                'target_type' => KnowledgeImprovementTarget::Documentation,

                'evidence' => [
                    [
                        'source_type' => 'task_attempt',
                        'source_id' => $attempt->id,
                        'task_id' => $task->id,
                        'task_key' => $task->key,
                        'task_attempt_id' => $attempt->id,
                        'attempt_number' => $attempt->number,
                    ],
                ],

                'evidence_hash' => str_repeat(
                    'e',
                    64,
                ),

                'proposed_change' => 'P5003-KNOWLEDGE-PROPOSAL-CONTENT-MUST-NOT-LEAK',
            ]);

        AuditEvent::create([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'event_type' => 'task.no_progress_detected',

            'payload' => [
                'operation' => 'coder',
                'attempt_number' => 1,
                'failure_fingerprint' => str_repeat(
                    'c',
                    64,
                ),
                'repository_fingerprint' => str_repeat(
                    'd',
                    64,
                ),
                'consecutive_repeat_count' => 1,
                'threshold' => 1,
                'changed_files' => [
                    'z.php',
                    'a.php',
                ],
                'reason' => 'P5003-PROVIDER-REASON-MUST-NOT-LEAK',
            ],

            'occurred_at' => now(),
        ]);

        $capsule = app(
            OrchestratorContextCapsuleFactory::class,
        )->make(
            $project,
            $task,
            $incident,
        );

        $encoded = p5003Json(
            $capsule,
        );

        expect(
            $capsule['current_agent_configuration']['role'],
        )
            ->toBe(
                AgentRole::Orchestrator->value,
            )
            ->and(
                $capsule['workflow_state']['task_status'],
            )
            ->toBe(
                TaskStatus::ChangesRequired->value,
            )
            ->and(
                $capsule['previous_attempt']['id'],
            )
            ->toBe(
                $attempt->id,
            )
            ->and(
                $capsule['validation_evidence']['failed_checks'],
            )
            ->toBe([
                'phpstan',
            ])
            ->and(
                $capsule['review']['id'],
            )
            ->toBe(
                $review->id,
            )
            ->and(
                $capsule['review_findings'][0]['id'],
            )
            ->toBe(
                $finding->id,
            )
            ->and(
                $capsule['current_failure_evidence']['failure_fingerprint'],
            )
            ->toBe(
                str_repeat(
                    'c',
                    64,
                ),
            )
            ->and(
                collect(
                    $capsule['older_history']['agent_runs'],
                )->pluck('id')->all(),
            )
            ->toContain(
                $run->id,
            )
            ->and(
                $capsule['older_history']['scorecard']['schema_version'],
            )
            ->not->toBeNull()
            ->and(
                $capsule['recovery_incident']['id'],
            )
            ->toBe(
                $incident->id,
            )
            ->and(
                $capsule['recovery_incident']['validation_evidence']['failed_checks'],
            )
            ->toBe([
                'regression',
            ])
            ->and(
                $capsule['older_history']['knowledge_improvements'][0]['id'],
            )
            ->toBe(
                $candidate->id,
            )
            ->and(
                $capsule['older_history']['knowledge_improvements'][0]['evidence_hash'],
            )
            ->toBe(
                str_repeat(
                    'e',
                    64,
                ),
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-KNOWLEDGE-PROPOSAL-CONTENT-MUST-NOT-LEAK',
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-PROVIDER-REASON-MUST-NOT-LEAK',
            );
    },
);

test(
    'unrelated project evidence cannot leak into a scoped Orchestrator capsule',
    function (): void {
        $project = p5003Project(
            'P5-003 isolated project',
        );

        $phase = p5003Phase(
            $project,
        );

        $task = p5003Task(
            $project,
            $phase,
            'P5-003',
            1,
        );

        $foreignProject = p5003Project(
            'P5-003 unrelated project',
        );

        $foreignPhase = p5003Phase(
            $foreignProject,
        );

        $foreignTask = p5003Task(
            $foreignProject,
            $foreignPhase,
            'FOREIGN-001',
            1,
        );

        $foreignRun = p5003CoderRun(
            $foreignProject,
            $foreignTask,
        );

        $foreignCandidate = KnowledgeImprovementCandidate::factory()
            ->for($foreignProject)
            ->create([
                'evidence' => [
                    [
                        'source_type' => 'task_attempt',
                        'source_id' => 999,
                        'task_id' => $foreignTask->id,
                    ],
                ],
                'proposed_change' => 'P5003-FOREIGN-KNOWLEDGE-SENTINEL',
            ]);

        AuditEvent::create([
            'project_id' => $foreignProject->id,
            'task_id' => $foreignTask->id,
            'event_type' => 'task.no_progress_detected',
            'payload' => [
                'failure_fingerprint' => 'P5003-FOREIGN-AUDIT-SENTINEL',
            ],
            'occurred_at' => now(),
        ]);

        $capsule = app(
            OrchestratorContextCapsuleFactory::class,
        )->make(
            $project,
            $task,
        );

        $encoded = p5003Json(
            $capsule,
        );

        expect(
            collect(
                $capsule['older_history']['agent_runs'],
            )->pluck('id')->all(),
        )
            ->not->toContain(
                $foreignRun->id,
            )
            ->and(
                collect(
                    $capsule['older_history']['knowledge_improvements'],
                )->pluck('id')->all(),
            )
            ->not->toContain(
                $foreignCandidate->id,
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-FOREIGN-KNOWLEDGE-SENTINEL',
            )
            ->and($encoded)
            ->not->toContain(
                'P5003-FOREIGN-AUDIT-SENTINEL',
            );
    },
);

test(
    'the public factory API exposes only trusted typed domain scope',
    function (): void {
        $method = new ReflectionMethod(
            OrchestratorContextCapsuleFactory::class,
            'make',
        );

        $parameters = $method->getParameters();

        expect(
            array_map(
                fn (
                    ReflectionParameter $parameter,
                ): string => $parameter->getName(),
                $parameters,
            ),
        )
            ->toBe([
                'project',
                'task',
                'recoveryIncident',
            ])
            ->and(
                (string) $parameters[0]->getType(),
            )
            ->toBe(
                Project::class,
            )
            ->and(
                $parameters[0]->allowsNull(),
            )
            ->toBeFalse()
            ->and(
                (string) $parameters[1]->getType(),
            )
            ->toBe(
                '?'.Task::class,
            )
            ->and(
                $parameters[1]->allowsNull(),
            )
            ->toBeTrue()
            ->and(
                (string) $parameters[2]->getType(),
            )
            ->toBe(
                '?'.RecoveryIncident::class,
            )
            ->and(
                $parameters[2]->allowsNull(),
            )
            ->toBeTrue();
    },
);

test(
    'Orchestrator context uses the existing deterministic Context Budget reduction path',
    function (): void {
        $project = p5003Project(
            'P5-003 Context Budget',
        );

        $phase = p5003Phase(
            $project,
        );

        $task = p5003Task(
            $project,
            $phase,
            'P5-003',
            1,
        );

        $agent = p5003Orchestrator();

        $capsule = app(
            OrchestratorContextCapsuleFactory::class,
        )->make(
            $project,
            $task,
        );

        /*
         * Expand only the existing reducible older_history source for this
         * Context Budget classification regression. Production assembly remains
         * bounded by OrchestratorContextCapsuleFactory itself.
         */
        unset(
            $capsule['capsule_hash'],
        );

        $capsule['older_history'] = [
            'bounded_test_history' => array_fill(
                0,
                80,
                [
                    'fingerprint' => str_repeat(
                        'f',
                        64,
                    ),
                    'evidence' => str_repeat(
                        'historical evidence ',
                        120,
                    ),
                ],
            ),
        ];

        $capsule['capsule_hash'] = hash(
            'sha256',
            p5003Json($capsule),
        );

        $assembled = app(
            AgentContextAssembler::class,
        )->assemble(
            $agent,
            AgentRole::Orchestrator,
            $capsule,
        );

        $prompt = "Orchestrator advisory contract.\n\n"
            .p5003Json(
                $assembled->toArray(),
            );

        $originalTokens = app(
            ContextCostEstimator::class,
        )
            ->measureValue(
                $prompt,
            )['estimated_tokens'];

        $capacityTokens = (int) ceil(
            $originalTokens / 0.76,
        );

        $capacity = [
            'harness' => 'codex',
            'model' => 'test',
            'resolved_capacity_tokens' => $capacityTokens,
            'max_output_tokens' => max(
                1,
                (int) floor(
                    $capacityTokens * 0.2,
                ),
            ),
            'capacity_source' => 'test:model',
            'capacity_source_version' => 1,
            'fallback' => false,
        ];

        $guard = app(
            ContextBudgetGuard::class,
        );

        $first = $guard->evaluate(
            AgentRole::Orchestrator,
            $prompt,
            $assembled,
            $capacity,
        );

        $second = $guard->evaluate(
            AgentRole::Orchestrator,
            $prompt,
            $assembled,
            $capacity,
        );

        expect(
            $first->blocked,
        )
            ->toBeFalse()
            ->and(
                $first->evidence['decision'],
            )
            ->toBe('reduced')
            ->and(
                $first->evidence['reduced_sources'],
            )
            ->toContain('older_history')
            ->and(
                $first->context?->taskContext['scope'],
            )
            ->toBe(
                $capsule['scope'],
            )
            ->and(
                $first->context?->hash,
            )
            ->toBe(
                $second->context?->hash,
            )
            ->and(
                $first->prompt,
            )
            ->toBe(
                $second->prompt,
            )
            ->and(
                $first->evidence,
            )
            ->toBe(
                $second->evidence,
            );
    },
);
