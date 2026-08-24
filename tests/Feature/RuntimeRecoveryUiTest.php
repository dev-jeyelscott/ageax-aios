<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoverabilityClassification;
use App\RuntimeRecoveryIncidentFamily;
use App\Services\SensitiveDataSanitizer;
use App\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

/** Resolve one singleton global Agent by its protected system role. */
function p7005GlobalAgent(AgentRole $role): Agent
{
    return Agent::query()
        ->whereNull('project_id')
        ->where('role', $role)
        ->sole();
}

/** Create one managed-project fixture for recovery command-center assertions. */
function p7005Project(string $name = 'P7-005 project'): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/p7-005-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/** Create one task fixture that can scope workflow or runtime recovery evidence. */
function p7005Task(
    Project $project,
    string $key = 'P7-005-TEST',
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => ((int) Task::query()
            ->where('project_id', $project->id)
            ->max('position')) + 1,
        'title' => 'Recovery command center fixture',
        'objective' => 'Expose durable recovery evidence to operators.',
        'acceptance_criteria' => [
            'Recovery evidence remains sanitized and attributable.',
        ],
        'implementation_prompt' => 'No execution is required for this fixture.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

test('the Recovery Engineer command center includes workflow and runtime incidents even without recovery runs', function () {
    $recoveryAgent = p7005GlobalAgent(AgentRole::RecoveryEngineer);
    $orchestrator = p7005GlobalAgent(AgentRole::Orchestrator);
    $knowledgeArchitect = p7005GlobalAgent(AgentRole::KnowledgeArchitect);
    $project = p7005Project();
    $task = p7005Task($project);

    $runtimeIncident = RecoveryIncident::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'failure_type' => RuntimeRecoveryIncidentFamily::ApplicationException->value,
        'fingerprint' => str_repeat('a', 64),
        'source' => 'route:projects.show',
        'exception_class' => RuntimeException::class,
        'occurrence_count' => 2,
        'first_seen_at' => now()->subMinutes(4),
        'last_seen_at' => now()->subMinute(),
        'status' => RecoveryIncidentStatus::Detected,
        'detected_at' => now(),
        'evidence' => [
            'message' => 'Sanitized runtime failure.',
            'stack' => [
                ['file' => 'app/Services/Example.php', 'line' => 42],
            ],
        ],
    ]);

    $workflowIncident = RecoveryIncident::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'failure_type' => 'task.blocked_dirty_repository',
        'status' => RecoveryIncidentStatus::Detected,
        'detected_at' => now()->subMinute(),
        'root_cause' => 'The managed project repository is dirty.',
        'root_cause_category' => 'unsafe_git_state',
    ]);

    $this
        ->actingAs(User::factory()->create())
        ->get(route('agents.show', $recoveryAgent))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('agents/show')
                ->where('agent.id', $recoveryAgent->id)
                ->where(
                    'agent.role',
                    AgentRole::RecoveryEngineer->value,
                )
                ->has('incidents.data', 2)
                ->where(
                    'incidents.data.0.id',
                    $runtimeIncident->id,
                )
                ->where(
                    'incidents.data.0.is_runtime',
                    true,
                )
                ->where(
                    'incidents.data.0.operator_outcome',
                    'automatic',
                )
                ->where(
                    'incidents.data.0.fingerprint',
                    str_repeat('a', 64),
                )
                ->where(
                    'incidents.data.0.occurrence_count',
                    2,
                )
                ->where(
                    'incidents.data.0.recovery_runs',
                    [],
                )
                ->where(
                    'incidents.data.0.circuit_breaker.state',
                    'closed',
                )
                ->where(
                    'incidents.data.1.id',
                    $workflowIncident->id,
                )
                ->where(
                    'incidents.data.1.is_runtime',
                    false,
                )
                ->where(
                    'incidents.data.1.fingerprint',
                    null,
                )
                ->where(
                    'incidents.data.1.evidence',
                    null,
                )
                ->where(
                    'incidents.data.1.circuit_breaker.state',
                    'not_applicable',
                ),
        );

    $this
        ->actingAs(User::factory()->create())
        ->get(route('agents.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page->where(
                'agents',
                function ($agents) use (
                    $recoveryAgent,
                    $orchestrator,
                    $knowledgeArchitect,
                ): bool {
                    $byRole = collect($agents)->keyBy('role');

                    return data_get(
                        $byRole,
                        AgentRole::RecoveryEngineer->value.'.id',
                    ) === $recoveryAgent->id
                        && data_get(
                            $byRole,
                            AgentRole::RecoveryEngineer->value.'.open_incident_count',
                        ) === 2
                        && data_get(
                            $byRole,
                            AgentRole::Orchestrator->value.'.id',
                        ) === $orchestrator->id
                        && data_get(
                            $byRole,
                            AgentRole::Orchestrator->value.'.open_incident_count',
                        ) === 0
                        && data_get(
                            $byRole,
                            AgentRole::KnowledgeArchitect->value.'.id',
                        ) === $knowledgeArchitect->id
                        && data_get(
                            $byRole,
                            AgentRole::KnowledgeArchitect->value.'.open_incident_count',
                        ) === 0;
                },
            ),
        );

    $this
        ->actingAs(User::factory()->create())
        ->get(route('agents.show', $orchestrator))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('agent.id', $orchestrator->id)
                ->where('incidents.data', []),
        );
});

test('runtime recovery evidence is sanitized bounded attributable and linked to Git validation and AgentRuns', function () {
    $recoveryAgent = p7005GlobalAgent(AgentRole::RecoveryEngineer);
    $project = p7005Project('Runtime recovery evidence');
    $task = p7005Task($project, 'P7-005-EVIDENCE');
    $rawMessage = 'Runtime failure api_key=runtime-ui-secret request_id=abc.';
    $sanitizedMessage = app(SensitiveDataSanitizer::class)
        ->sanitizeText($rawMessage);
    $rawStack = [];
    $expectedStack = [];

    foreach (range(1, 10) as $index) {
        $rawStack[] = [
            'file' => "app/Services/Runtime{$index}.php",
            'line' => $index,
            'class' => "App\\Services\\Runtime{$index}",
            'function' => 'handle',
            'args' => ['must-not-render'],
            'object' => 'must-not-render',
        ];

        if ($index <= 8) {
            $expectedStack[] = [
                'file' => "app/Services/Runtime{$index}.php",
                'line' => $index,
                'class' => "App\\Services\\Runtime{$index}",
                'function' => 'handle',
            ];
        }
    }

    $incident = RecoveryIncident::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'failure_type' => RuntimeRecoveryIncidentFamily::ApplicationException->value,
        'fingerprint' => str_repeat('b', 64),
        'source' => 'route:runtime.fail',
        'exception_class' => RuntimeException::class,
        'occurrence_count' => 4,
        'first_seen_at' => now()->subMinutes(10),
        'last_seen_at' => now()->subMinute(),
        'status' => RecoveryIncidentStatus::Escalated,
        'detected_at' => now(),
        'evidence' => [
            'message' => $rawMessage,
            'stack' => $rawStack,
            'request_payload' => [
                'password' => 'must-not-render',
            ],
        ],
        'root_cause' => 'A bounded project runtime failure repeatedly failed repair.',
        'root_cause_category' => RuntimeRecoverabilityClassification::CandidateAiRepair->value,
        'recoverable' => false,
        'attempt_count' => 2,
        'fix_summary' => 'A proposed repair did not pass final validation.',
        'validation_evidence' => [
            'passed' => false,
            'checks' => [
                'git_diff_check' => false,
                'configured_validation_commands' => true,
            ],
            'evidence' => [
                'provider_output' => 'must-not-render',
            ],
        ],
        'escalation_reason' => 'Runtime recovery circuit breaker opened after repeated failed repairs.',
        'base_sha' => str_repeat('1', 40),
        'head_sha' => str_repeat('2', 40),
        'commit_sha' => str_repeat('3', 40),
        'changed_files' => [
            'app/Services/RuntimeRepair.php',
            'tests/Feature/RuntimeRepairTest.php',
        ],
        'resolved_at' => now(),
    ]);

    $olderRun = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'recovery_incident_id' => $incident->id,
        'agent_id' => $recoveryAgent->id,
        'role' => AgentRole::RecoveryEngineer,
        'status' => AgentRunStatus::Failed,
        'attempt_number' => 1,
        'prompt_hash' => hash(
            'sha256',
            'first recovery attempt',
        ),
        'exit_code' => 1,
        'started_at' => now()->subMinutes(3),
        'finished_at' => now()->subMinutes(2),
    ]);

    $newerRun = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'recovery_incident_id' => $incident->id,
        'agent_id' => $recoveryAgent->id,
        'role' => AgentRole::RecoveryEngineer,
        'status' => AgentRunStatus::Completed,
        'attempt_number' => 2,
        'prompt_hash' => hash(
            'sha256',
            'second recovery attempt',
        ),
        'exit_code' => 0,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'recovery.runtime_attempt_failed',
        'payload' => [
            'recovery_incident_id' => $incident->id,
            'classification' => RuntimeRecoverabilityClassification::CandidateAiRepair->value,
            'reason' => 'AIOS validation failed.',
        ],
        'occurred_at' => now()->subSecond(),
    ]);

    AuditEvent::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'event_type' => 'recovery.runtime_circuit_breaker_opened',
        'payload' => [
            'recovery_incident_id' => $incident->id,
            'failure_fingerprint' => str_repeat('c', 64),
            'consecutive_repeat_count' => 3,
            'threshold' => 3,
            'attempt_count' => 2,
        ],
        'occurred_at' => now(),
    ]);

    $this
        ->actingAs(User::factory()->create())
        ->get(route('agents.show', $recoveryAgent))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where(
                    'incidents.data.0.id',
                    $incident->id,
                )
                ->where(
                    'incidents.data.0.operator_outcome',
                    'blocked',
                )
                ->where(
                    'incidents.data.0.evidence.message',
                    $sanitizedMessage,
                )
                ->where(
                    'incidents.data.0.evidence.stack',
                    $expectedStack,
                )
                ->has(
                    'incidents.data.0.recovery_runs',
                    2,
                )
                ->where(
                    'incidents.data.0.recovery_runs.0.id',
                    $newerRun->id,
                )
                ->where(
                    'incidents.data.0.recovery_runs.0.attempt_number',
                    2,
                )
                ->where(
                    'incidents.data.0.recovery_runs.0.viewable_in_agent_console',
                    true,
                )
                ->where(
                    'incidents.data.0.recovery_runs.1.id',
                    $olderRun->id,
                )
                ->where(
                    'incidents.data.0.git.commit_sha',
                    str_repeat('3', 40),
                )
                ->where(
                    'incidents.data.0.git.changed_files',
                    [
                        'app/Services/RuntimeRepair.php',
                        'tests/Feature/RuntimeRepairTest.php',
                    ],
                )
                ->where(
                    'incidents.data.0.validation.passed',
                    false,
                )
                ->where(
                    'incidents.data.0.validation.checks',
                    [
                        [
                            'name' => 'configured_validation_commands',
                            'passed' => true,
                        ],
                        [
                            'name' => 'git_diff_check',
                            'passed' => false,
                        ],
                    ],
                )
                ->where(
                    'incidents.data.0.circuit_breaker.state',
                    'opened',
                )
                ->where(
                    'incidents.data.0.circuit_breaker.consecutive_repeat_count',
                    3,
                )
                ->where(
                    'incidents.data.0.circuit_breaker.threshold',
                    3,
                )
                ->where(
                    'incidents.data.0.circuit_breaker.failure_fingerprint',
                    str_repeat('c', 64),
                )
                ->where(
                    'incidents.data.0.blocking.state',
                    'blocked',
                ),
        );

    $this
        ->actingAs(User::factory()->create())
        ->get(
            route(
                'agents.runs.show',
                [$recoveryAgent, $newerRun],
            ),
        )
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where(
                    'agent_run.id',
                    $newerRun->id,
                )
                ->missing('agent_run.log_path')
                ->missing('agent_run.live_output')
                ->missing('agent_run.result'),
        );
});

test('operator outcomes distinguish automatic escalated resolved and blocked recovery states', function () {
    $recoveryAgent = p7005GlobalAgent(
        AgentRole::RecoveryEngineer,
    );
    $project = p7005Project('Outcome states');

    $automatic = RecoveryIncident::create([
        'project_id' => $project->id,
        'failure_type' => RuntimeRecoveryIncidentFamily::ApplicationException->value,
        'fingerprint' => str_repeat('d', 64),
        'source' => 'route:auto',
        'occurrence_count' => 1,
        'status' => RecoveryIncidentStatus::Detected,
        'detected_at' => now(),
    ]);

    $escalated = RecoveryIncident::create([
        'project_id' => $project->id,
        'failure_type' => RuntimeRecoveryIncidentFamily::ApplicationException->value,
        'fingerprint' => str_repeat('e', 64),
        'source' => 'route:security',
        'occurrence_count' => 1,
        'status' => RecoveryIncidentStatus::Escalated,
        'detected_at' => now()->subSecond(),
        'root_cause_category' => RuntimeRecoverabilityClassification::OperatorOnly->value,
        'recoverable' => false,
        'escalation_reason' => 'Protected runtime incident requires operator review.',
        'resolved_at' => now(),
    ]);

    $resolved = RecoveryIncident::create([
        'project_id' => $project->id,
        'failure_type' => RuntimeRecoveryIncidentFamily::SystemWorkerFailure->value,
        'fingerprint' => str_repeat('f', 64),
        'source' => 'worker:expired_lease',
        'occurrence_count' => 1,
        'status' => RecoveryIncidentStatus::Recovered,
        'detected_at' => now()->subSeconds(2),
        'root_cause_category' => RuntimeRecoverabilityClassification::KnownDeterministicRepair->value,
        'recoverable' => true,
        'fix_summary' => 'Deterministic stale-worker recovery completed.',
        'resolved_at' => now(),
    ]);

    $blocked = RecoveryIncident::create([
        'project_id' => $project->id,
        'failure_type' => RuntimeRecoveryIncidentFamily::ApplicationException->value,
        'fingerprint' => str_repeat('9', 64),
        'source' => 'route:blocked',
        'occurrence_count' => 1,
        'status' => RecoveryIncidentStatus::Escalated,
        'detected_at' => now()->subSeconds(3),
        'root_cause_category' => RuntimeRecoverabilityClassification::CandidateAiRepair->value,
        'recoverable' => false,
        'escalation_reason' => 'AIOS recovery repository preflight failed before runtime AI repair.',
        'resolved_at' => now(),
    ]);

    $this
        ->actingAs(User::factory()->create())
        ->get(route('agents.show', $recoveryAgent))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where(
                    'incidents.data.0.id',
                    $automatic->id,
                )
                ->where(
                    'incidents.data.0.operator_outcome',
                    'automatic',
                )
                ->where(
                    'incidents.data.1.id',
                    $escalated->id,
                )
                ->where(
                    'incidents.data.1.operator_outcome',
                    'escalated',
                )
                ->where(
                    'incidents.data.2.id',
                    $resolved->id,
                )
                ->where(
                    'incidents.data.2.operator_outcome',
                    'resolved',
                )
                ->where(
                    'incidents.data.3.id',
                    $blocked->id,
                )
                ->where(
                    'incidents.data.3.operator_outcome',
                    'blocked',
                )
                ->where(
                    'incidents.data.3.blocking.state',
                    'blocked',
                ),
        );
});

test('legacy workflow incidents remain nullable safe and paginated', function () {
    $recoveryAgent = p7005GlobalAgent(
        AgentRole::RecoveryEngineer,
    );
    $project = p7005Project(
        'Legacy recovery pagination',
    );

    foreach (range(1, 21) as $index) {
        RecoveryIncident::create([
            'project_id' => $project->id,
            'failure_type' => 'task.blocked_dirty_repository',
            'status' => RecoveryIncidentStatus::Recovered,
            'detected_at' => now()->subMinutes($index),
            'resolved_at' => now()->subMinutes(
                $index - 1,
            ),
        ]);
    }

    $this
        ->actingAs(User::factory()->create())
        ->get(
            route(
                'agents.show',
                [
                    'agent' => $recoveryAgent,
                    'page' => 2,
                ],
            ),
        )
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where(
                    'incidents.current_page',
                    2,
                )
                ->where(
                    'incidents.total',
                    21,
                )
                ->has(
                    'incidents.data',
                    1,
                )
                ->where(
                    'incidents.data.0.is_runtime',
                    false,
                )
                ->where(
                    'incidents.data.0.fingerprint',
                    null,
                )
                ->where(
                    'incidents.data.0.source',
                    null,
                )
                ->where(
                    'incidents.data.0.exception_class',
                    null,
                )
                ->where(
                    'incidents.data.0.first_seen_at',
                    null,
                )
                ->where(
                    'incidents.data.0.last_seen_at',
                    null,
                )
                ->where(
                    'incidents.data.0.evidence',
                    null,
                )
                ->where(
                    'incidents.data.0.git',
                    null,
                )
                ->where(
                    'incidents.data.0.validation',
                    null,
                )
                ->where(
                    'incidents.data.0.circuit_breaker.state',
                    'not_applicable',
                )
                ->where(
                    'incidents.next_page_url',
                    null,
                ),
        );
});
