<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\AgentRunStatus;
use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\AuditEvent;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\AssembledAgentContext;
use App\Services\ContextBudgetGuard;
use App\Services\GlobalAgentResolver;
use App\Services\HarnessCapabilities;
use App\Services\KnowledgeArchitectAdvisor;
use App\Services\NormalizedExecutionResult;
use App\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Test harness that records the execution project and prompt while returning deterministic structured output.
 */
final class KnowledgeArchitectTestHarness implements AgentHarness
{
    /** @var list<string> */
    public array $paths = [];

    /** @var list<string> */
    public array $prompts = [];

    /**
     * Create the fake advisory harness with one configurable provider output.
     */
    public function __construct(
        public string $output,
    ) {}

    /**
     * Identify the fake as Claude Code to match the seeded Knowledge Architect configuration.
     */
    public function identifier(): AgentHarnessIdentifier
    {
        return AgentHarnessIdentifier::ClaudeCode;
    }

    /**
     * Expose deterministic capacity metadata for resolver compatibility.
     */
    public function capabilities(): HarnessCapabilities
    {
        return new HarnessCapabilities(
            defaultContextWindowTokens: 200000,
            defaultMaxOutputTokens: 64000,
            capacityMetadataSource: 'knowledge_architect_test',
            capacityMetadataVersion: 1,
        );
    }

    /**
     * Record the isolated execution boundary and return the configured provider result.
     */
    public function execute(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        array $executionSettings = [],
    ): NormalizedExecutionResult {
        $this->paths[] = $project->path;
        $this->prompts[] = $prompt;

        return new NormalizedExecutionResult(
            exitCode: 0,
            output: $this->output,
            errorOutput: '',
        );
    }

    /**
     * Change only the next provider output while preserving captured execution evidence.
     */
    public function respondWith(string $output): void
    {
        $this->output = $output;
    }
}

beforeEach(function (): void {
    $this->workspace =
        sys_get_temp_dir()
        .'/ageax-knowledge-architect-workspace-'
        .Str::uuid();

    $this->vault =
        sys_get_temp_dir()
        .'/ageax-knowledge-architect-vault-'
        .Str::uuid();

    File::ensureDirectoryExists(
        $this->workspace,
    );

    File::ensureDirectoryExists(
        $this->vault,
    );

    config()->set(
        'aios.workspace_root',
        $this->workspace,
    );

    config()->set(
        'aios.obsidian_vault_path',
        $this->vault,
    );

    config()->set(
        'aios.obsidian_context_max_characters',
        4000,
    );

    config()->set(
        'aios.obsidian_context_max_note_characters',
        2000,
    );

    config()->set(
        'aios.obsidian_context_max_notes',
        4,
    );
});

afterEach(function (): void {
    File::deleteDirectory(
        $this->workspace,
    );

    File::deleteDirectory(
        $this->vault,
    );
});

/**
 * Create one managed project inside the configured external test workspace.
 */
function knowledgeArchitectProject(
    string $workspace,
    string $name,
): Project {
    $path =
        $workspace
        .'/'
        .Str::slug($name)
        .'-'
        .Str::uuid();

    File::ensureDirectoryExists($path);

    return Project::factory()->create([
        'name' => $name,
        'path' => $path,
    ]);
}

/**
 * Create one deterministic candidate compatible with the P6-002 persistence contract.
 *
 * @param  list<array<string, mixed>>  $evidence
 */
function knowledgeArchitectCandidate(
    Project $project,
    string $failureCode,
    array $evidence,
    string $affectedArea = 'knowledge',
): KnowledgeImprovementCandidate {
    $fingerprint = hash(
        'sha256',
        implode('|', [
            (string) $project->id,
            $failureCode,
            $affectedArea,
        ]),
    );

    $evidenceHash = hash(
        'sha256',
        json_encode(
            $evidence,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES,
        ),
    );

    return KnowledgeImprovementCandidate::query()
        ->create([
            'project_id' => $project->id,
            'fingerprint' => $fingerprint,
            'source_kind' => 'knowledge_gap',
            'failure_code' => $failureCode,
            'affected_role' => null,
            'affected_area' => $affectedArea,
            'status' => KnowledgeImprovementCandidateStatus::Pending,
            'target_type' => KnowledgeImprovementTarget::Documentation,
            'evidence_summary' => 'Deterministic evidence requires bounded semantic interpretation.',
            'proposed_change' => 'Review the deterministic evidence before changing knowledge.',
            'evidence' => $evidence,
            'occurrence_count' => 1,
            'evidence_hash' => $evidenceHash,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
}

/**
 * Create one current project-local Obsidian source and durable manifest evidence.
 */
function knowledgeArchitectSource(
    string $vault,
    Project $project,
    string $reference,
    string $content,
): KnowledgeSourceManifest {
    $directory =
        $vault
        .'/Projects/'
        .Str::slug($project->name)
        .'/'
        .dirname($reference);

    File::ensureDirectoryExists($directory);

    File::put(
        $vault
        .'/Projects/'
        .Str::slug($project->name)
        .'/'
        .$reference,
        $content,
    );

    return KnowledgeSourceManifest::query()
        ->create([
            'project_id' => $project->id,
            'source_type' => 'obsidian',
            'source_reference' => $reference,
            'content_hash' => hash(
                'sha256',
                $content,
            ),
            'git_sha' => null,
            'discovered_at' => now(),
            'last_verified_at' => now(),
        ]);
}

/**
 * Replace the production harness resolver with one isolated deterministic test harness.
 */
function bindKnowledgeArchitectHarness(
    KnowledgeArchitectTestHarness $harness,
): void {
    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([
            $harness,
        ]),
    );
}

/**
 * Build a valid structured Knowledge Architect provider response.
 */
function knowledgeArchitectResponse(
    string $summary,
    ?string $proposedChange,
    string $action = 'enrich',
): string {
    return json_encode([
        'schema_version' => 1,
        'action' => $action,
        'evidence_summary' => $summary,
        'proposed_change' => $proposedChange,
        'confidence' => 'high',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * Build the exact deterministic provider prompt boundary required by ContextBudgetGuard.
 */
function knowledgeArchitectBudgetPrompt(
    AssembledAgentContext $context,
): string {
    return implode("\n", [
        'Knowledge Architect Context Budget test.',
        '',
        'AIOS assembled context:',
        json_encode(
            $context->toArray(),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        ),
    ]);
}

test('Knowledge Architect is a singleton global-only workerless Agent', function () {
    $agent = app(
        GlobalAgentResolver::class,
    )->forRole(
        AgentRole::KnowledgeArchitect,
    );

    expect($agent->project_id)->toBeNull()
        ->and($agent->role)
        ->toBe(AgentRole::KnowledgeArchitect)
        ->and(
            AgentWorker::query()
                ->where(
                    'agent_id',
                    $agent->id,
                )
                ->exists(),
        )
        ->toBeFalse();

    $project = knowledgeArchitectProject(
        $this->workspace,
        'Project scoped architect',
    );

    expect(fn () => Agent::query()->create([
        'project_id' => $project->id,
        'name' => 'Invalid Project Knowledge Architect',
        'role' => AgentRole::KnowledgeArchitect,
        'harness' => AgentHarnessIdentifier::ClaudeCode,
        'enabled' => true,
    ]))->toThrow(
        LogicException::class,
        'Agent role must be a supported AIOS workflow role.',
    );

    expect(fn () => Agent::query()->create([
        'name' => 'Duplicate Global Knowledge Architect',
        'role' => AgentRole::KnowledgeArchitect,
        'harness' => AgentHarnessIdentifier::ClaudeCode,
        'enabled' => true,
    ]))->toThrow(QueryException::class);
});

test('Knowledge Architect resolves through both existing harnesses and the existing Context Budget policy', function () {
    $agent = app(
        GlobalAgentResolver::class,
    )->forRole(
        AgentRole::KnowledgeArchitect,
    );

    $resolver = app(
        AgentHarnessResolver::class,
    );

    foreach ([
        AgentHarnessIdentifier::Codex,
        AgentHarnessIdentifier::ClaudeCode,
    ] as $identifier) {
        $agent->update([
            'harness' => $identifier,
            'model' => null,
            'reasoning_setting' => null,
        ]);

        $agent->refresh();

        $harness = $resolver->resolve(
            $agent,
        );

        $context = app(
            AgentContextAssembler::class,
        )->assemble(
            $agent,
            AgentRole::KnowledgeArchitect,
            [
                'objective' => 'Analyze bounded deterministic knowledge evidence only.',
            ],
        );

        $capacity = $harness
            ->capabilities()
            ->resolveContextCapacity(
                $agent,
                $identifier,
            );

        $decision = app(
            ContextBudgetGuard::class,
        )->evaluate(
            AgentRole::KnowledgeArchitect,
            knowledgeArchitectBudgetPrompt(
                $context,
            ),
            $context,
            $capacity,
        );

        expect($harness->identifier())
            ->toBe($identifier)
            ->and($decision->blocked)
            ->toBeFalse()
            ->and(
                $decision->evidence['role'],
            )
            ->toBe(
                AgentRole::KnowledgeArchitect->value,
            )
            ->and(
                $decision->evidence[
                    'target_percent'
                ],
            )
            ->toBe(70)
            ->and(
                $decision->evidence[
                    'warning_percent'
                ],
            )
            ->toBe(75)
            ->and(
                $decision->evidence[
                    'hard_ceiling_percent'
                ],
            )
            ->toBe(80)
            ->and(
                $decision->evidence[
                    'reserved_percent'
                ],
            )
            ->toBe(20);
    }
});

test('semantic advisory uses isolated fresh context, persists evidence, and remains idempotent for unchanged evidence', function () {
    $project = knowledgeArchitectProject(
        $this->workspace,
        'Semantic advisory project',
    );

    $sourceContent =
        'Current architecture says Laravel remains the durable workflow authority.';

    $manifest = knowledgeArchitectSource(
        $this->vault,
        $project,
        'Architecture/System.md',
        $sourceContent,
    );

    knowledgeArchitectSource(
        $this->vault,
        $project,
        'Notes/Unrelated.md',
        'UNRELATED-KNOWLEDGE-MUST-NOT-BE-INJECTED',
    );

    $candidate = knowledgeArchitectCandidate(
        $project,
        'knowledge_gap:content_hash_drift',
        [[
            'source_type' => 'knowledge_gap',
            'source_id' => hash(
                'sha256',
                'content-drift',
            ),
            'detector' => 'content_hash_drift',
            'source_reference' => 'Architecture/System.md',
            'target_reference' => null,
            'replacement_manifest_id' => $manifest->id,
            'previous_content_hash' => hash('sha256', 'previous'),
            'current_content_hash' => $manifest->content_hash,
        ]],
    );

    $task = Task::query()->create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Unrelated queued work',
        'objective' => 'Remain unchanged during Knowledge Architect analysis.',
        'acceptance_criteria' => [
            'Workflow state remains AIOS-owned.',
        ],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => [],
        'verification_commands' => [],
        'implementation_prompt' => 'Do not mutate this task.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);

    $sourcePath =
        $this->vault
        .'/Projects/'
        .Str::slug($project->name)
        .'/Architecture/System.md';

    $sourceHashBefore = hash_file(
        'sha256',
        $sourcePath,
    );

    $harness =
        new KnowledgeArchitectTestHarness(
            knowledgeArchitectResponse(
                'The changed architecture note may contradict downstream guidance and requires operator review.',
                'Review dependent documentation against the current Laravel authority statement before applying any documentation change.',
            ),
        );

    bindKnowledgeArchitectHarness(
        $harness,
    );

    expect(
        app(
            KnowledgeArchitectAdvisor::class,
        )->analyze($project),
    )->toBe(1);

    $candidate->refresh();

    $firstRun = AgentRun::query()
        ->findOrFail(
            $candidate
                ->knowledge_architect_agent_run_id,
        );

    $firstContextHash = data_get(
        $firstRun->configuration_snapshot,
        'context_hash',
    );

    expect(
        $candidate
            ->knowledge_architect_evidence_hash,
    )
        ->toBe($candidate->evidence_hash)
        ->and($candidate->evidence_summary)
        ->toContain('may contradict')
        ->and($candidate->proposed_change)
        ->toContain(
            'Review dependent documentation',
        )
        ->and($firstRun->role)
        ->toBe(
            AgentRole::KnowledgeArchitect,
        )
        ->and($firstRun->agent_worker_id)
        ->toBeNull()
        ->and(
            $firstRun->configuration_snapshot,
        )
        ->toBeArray()
        ->and($harness->paths)
        ->toHaveCount(1)
        ->and($harness->paths[0])
        ->not->toBe($project->path)
        ->and(
            File::exists(
                $harness->paths[0],
            ),
        )
        ->toBeFalse()
        ->and($harness->prompts[0])
        ->toContain($sourceContent)
        ->and($harness->prompts[0])
        ->not->toContain(
            'UNRELATED-KNOWLEDGE-MUST-NOT-BE-INJECTED',
        )
        ->and(
            hash_file(
                'sha256',
                $sourcePath,
            ),
        )
        ->toBe($sourceHashBefore)
        ->and($task->refresh()->status)
        ->toBe(TaskStatus::Queued)
        ->and(
            AgentRun::query()
                ->whereBelongsTo($project)
                ->where(
                    'role',
                    AgentRole::KnowledgeArchitect,
                )
                ->count(),
        )
        ->toBe(1);

    expect(
        app(
            KnowledgeArchitectAdvisor::class,
        )->analyze($project),
    )
        ->toBe(0)
        ->and($harness->paths)
        ->toHaveCount(1)
        ->and(
            AgentRun::query()
                ->whereBelongsTo($project)
                ->where(
                    'role',
                    AgentRole::KnowledgeArchitect,
                )
                ->count(),
        )
        ->toBe(1);

    $newEvidence = [
        ...$candidate->evidence,
        [
            'source_type' => 'knowledge_gap',
            'source_id' => hash(
                'sha256',
                'new-drift-evidence',
            ),
            'source_reference' => 'Architecture/System.md',
            'current_content_hash' => hash(
                'sha256',
                'newer-content',
            ),
        ],
    ];

    $candidate->update([
        'evidence' => $newEvidence,
        'evidence_hash' => hash(
            'sha256',
            json_encode(
                $newEvidence,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES,
            ),
        ),
    ]);

    $harness->respondWith(
        knowledgeArchitectResponse(
            'The newly changed deterministic evidence requires a fresh semantic review.',
            'Re-evaluate only the newly supplied evidence before proposing documentation work.',
        ),
    );

    expect(
        app(
            KnowledgeArchitectAdvisor::class,
        )->analyze($project),
    )->toBe(1);

    $candidate->refresh();

    $secondRun = AgentRun::query()
        ->findOrFail(
            $candidate
                ->knowledge_architect_agent_run_id,
        );

    expect($secondRun->id)
        ->not->toBe($firstRun->id)
        ->and(
            data_get(
                $secondRun
                    ->configuration_snapshot,
                'context_hash',
            ),
        )
        ->not->toBe($firstContextHash)
        ->and($harness->paths)
        ->toHaveCount(2)
        ->and(
            AgentRun::query()
                ->whereBelongsTo($project)
                ->where(
                    'role',
                    AgentRole::KnowledgeArchitect,
                )
                ->count(),
        )
        ->toBe(2);
});

test('malformed semantic output fails the AgentRun without mutating the candidate or knowledge source', function () {
    $project = knowledgeArchitectProject(
        $this->workspace,
        'Malformed advisory project',
    );

    $sourceContent =
        'Implementation note with stale repository guidance.';

    knowledgeArchitectSource(
        $this->vault,
        $project,
        'Implementation/Legacy.md',
        $sourceContent,
    );

    $candidate = knowledgeArchitectCandidate(
        $project,
        'knowledge_gap:obsolete_implementation_note',
        [[
            'source_type' => 'knowledge_gap',
            'source_id' => hash(
                'sha256',
                'stale-note',
            ),
            'detector' => 'obsolete_implementation_note',
            'source_reference' => 'Implementation/Legacy.md',
            'target_reference' => 'app/RemovedService.php',
        ]],
    );

    $originalSummary =
        $candidate->evidence_summary;

    $originalProposal =
        $candidate->proposed_change;

    $sourcePath =
        $this->vault
        .'/Projects/'
        .Str::slug($project->name)
        .'/Implementation/Legacy.md';

    $sourceHashBefore = hash_file(
        'sha256',
        $sourcePath,
    );

    bindKnowledgeArchitectHarness(
        new KnowledgeArchitectTestHarness(
            'not-json',
        ),
    );

    expect(
        app(
            KnowledgeArchitectAdvisor::class,
        )->analyze($project),
    )->toBe(0);

    $candidate->refresh();

    $run = AgentRun::query()
        ->whereBelongsTo($project)
        ->where(
            'role',
            AgentRole::KnowledgeArchitect,
        )
        ->sole();

    expect($run->status)
        ->toBe(AgentRunStatus::Failed)
        ->and(
            $candidate
                ->knowledge_architect_agent_run_id,
        )
        ->toBeNull()
        ->and(
            $candidate
                ->knowledge_architect_evidence_hash,
        )
        ->toBeNull()
        ->and($candidate->evidence_summary)
        ->toBe($originalSummary)
        ->and($candidate->proposed_change)
        ->toBe($originalProposal)
        ->and(
            hash_file(
                'sha256',
                $sourcePath,
            ),
        )
        ->toBe($sourceHashBefore);
});

test('cross-project recurring-pattern context exposes aggregate evidence only', function () {
    $projectA = knowledgeArchitectProject(
        $this->workspace,
        'Pattern Project A',
    );

    $projectB = knowledgeArchitectProject(
        $this->workspace,
        'Pattern Project B',
    );

    $candidateA = knowledgeArchitectCandidate(
        $projectA,
        'review_finding:architecture_consistency:major',
        [[
            'source_type' => 'review_finding',
            'source_id' => 101,
        ]],
        'app/Services',
    );

    $candidateB = knowledgeArchitectCandidate(
        $projectB,
        'review_finding:architecture_consistency:major',
        [[
            'source_type' => 'review_finding',
            'source_id' => 202,
            'private_detail' => 'FOREIGN-PROJECT-CONTENT-MUST-NOT-LEAK',
        ]],
        'app/Services',
    );

    $candidateB->update([
        'evidence_summary' => 'FOREIGN-PROJECT-CONTENT-MUST-NOT-LEAK',
    ]);

    $harness =
        new KnowledgeArchitectTestHarness(
            knowledgeArchitectResponse(
                'The normalized failure family recurs across multiple projects and warrants an operator-reviewed proposal.',
                null,
                'no_change',
            ),
        );

    bindKnowledgeArchitectHarness(
        $harness,
    );

    expect(
        app(
            KnowledgeArchitectAdvisor::class,
        )->analyze($projectA),
    )->toBe(1);

    $prompt = $harness->prompts[0];

    expect(
        $candidateA
            ->refresh()
            ->knowledge_architect_agent_run_id,
    )
        ->not->toBeNull()
        ->and($prompt)
        ->toContain(
            'cross_project_recurring_pattern',
        )
        ->and($prompt)
        ->toContain(
            'matching_project_count',
        )
        ->and($prompt)
        ->not->toContain($projectB->name)
        ->and($prompt)
        ->not->toContain($projectB->path)
        ->and($prompt)
        ->not->toContain(
            'FOREIGN-PROJECT-CONTENT-MUST-NOT-LEAK',
        );
});

test('a disabled Knowledge Architect blocks advisory execution without blocking deterministic candidate persistence', function () {
    $project = knowledgeArchitectProject(
        $this->workspace,
        'Disabled architect project',
    );

    knowledgeArchitectCandidate(
        $project,
        'knowledge_gap:content_hash_drift',
        [[
            'source_type' => 'knowledge_gap',
            'source_id' => hash(
                'sha256',
                'disabled-architect',
            ),
            'source_reference' => 'Architecture/System.md',
        ]],
    );

    $agent = app(
        GlobalAgentResolver::class,
    )->forRole(
        AgentRole::KnowledgeArchitect,
    );

    $agent->update([
        'enabled' => false,
    ]);

    expect(
        app(
            KnowledgeArchitectAdvisor::class,
        )->analyze($project),
    )
        ->toBe(0)
        ->and(
            AgentRun::query()
                ->whereBelongsTo($project)
                ->where(
                    'role',
                    AgentRole::KnowledgeArchitect,
                )
                ->exists(),
        )
        ->toBeFalse()
        ->and(
            AuditEvent::query()
                ->whereBelongsTo($project)
                ->where(
                    'event_type',
                    'knowledge_architect.unavailable',
                )
                ->exists(),
        )
        ->toBeTrue();
});
