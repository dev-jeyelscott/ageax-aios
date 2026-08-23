<?php

use App\Actions\PromoteGlobalKnowledgePattern;
use App\AgentRole;
use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\GlobalKnowledgePattern;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\Services\ObsidianProjectNotes;
use App\Services\TaskContextCapsuleFactory;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Create one running project with a unique bounded test path.
 */
function retrievalRankingProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/ageax-retrieval-ranking-'.Str::uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Configure an isolated Obsidian project directory for deterministic retrieval tests.
 */
function retrievalRankingVault(Project $project): string
{
    $vault = storage_path(
        'framework/testing/obsidian-retrieval-ranking-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);

    $directory = $vault.'/Projects/'.Str::slug($project->name);
    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * Persist manifest evidence for one exact project-local Obsidian source version.
 */
function retrievalRankingManifest(
    Project $project,
    string $reference,
    string $content,
    bool $current = true,
): KnowledgeSourceManifest {
    return KnowledgeSourceManifest::factory()
        ->for($project)
        ->create([
            'source_type' => 'obsidian',
            'source_reference' => $reference,
            'content_hash' => hash('sha256', $content),
            'superseded_at' => $current ? null : now(),
            'superseded_by_id' => null,
        ]);
}

/**
 * Explicitly promote one operator-approved reusable pattern through the P6-004 domain action.
 *
 * @param  list<string>  $roles
 */
function retrievalRankingPromotePattern(
    Project $sourceProject,
    string $name,
    array $roles = ['coder'],
    string $category = 'workflow',
): GlobalKnowledgePattern {
    $operator = User::factory()->create();
    $candidate = KnowledgeImprovementCandidate::factory()
        ->for($sourceProject)
        ->create([
            'status' => KnowledgeImprovementCandidateStatus::Approved,
            'decided_by_user_id' => $operator->id,
            'decided_at' => now(),
            'target_type' => KnowledgeImprovementTarget::Documentation,
            'affected_role' => $roles[0] ?? 'coder',
            'affected_area' => 'app/Services',
            'proposed_change' => 'Preserve deterministic application-owned knowledge selection with focused regression evidence.',
        ]);

    return app(PromoteGlobalKnowledgePattern::class)->handle(
        $candidate,
        $operator,
        [
            'name' => $name,
            'category' => $category,
            'applicable_roles' => $roles,
            'validated_guidance' => 'Use only deterministic application-owned retrieval evidence and preserve the configured context budget.',
        ],
    );
}

test('task retrieval applies deterministic source precedence and explains every selected source', function (): void {
    config()->set('aios.obsidian_context_max_notes', 8);
    config()->set('aios.obsidian_context_max_characters', 20000);
    config()->set('aios.obsidian_context_max_note_characters', 4000);

    $project = retrievalRankingProject('Ranked Knowledge Project');
    $directory = retrievalRankingVault($project);
    $task = Task::query()->create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Improve ranking',
        'objective' => 'Rank bounded knowledge deterministically.',
        'acceptance_criteria' => ['Every selected source is explainable.'],
        'relevant_paths' => ['app/Services/KnowledgeService.php'],
        'implementation_prompt' => 'Implement deterministic ranking.',
        'context_capsule' => [
            'obsidian_notes' => ['Specifications/Explicit.md'],
        ],
        'status' => TaskStatus::Coding,
    ]);

    $sources = [
        'Task Briefs/TASK-001 - improve-ranking.md' => 'CURRENT TASK BRIEF [[Specifications/Explicit.md]]',
        'STATE.md' => 'CURRENT STATE',
        'Specifications/Explicit.md' => 'EXPLICIT KNOWLEDGE [[Hidden/Recursive.md]]',
        'Implementation/app/Services/KnowledgeService.php.md' => 'EXACT RELEVANT PATH KNOWLEDGE',
        'Notes/app/Services.md' => 'SAME AFFECTED AREA KNOWLEDGE',
        'ADR/app/Services.md' => 'CURRENT RELATED ADR',
        'Hidden/Recursive.md' => 'MUST NOT BE FOLLOWED RECURSIVELY',
        'Unrelated/Hidden.md' => 'MUST NOT BE RETRIEVED',
    ];

    foreach ($sources as $reference => $content) {
        File::ensureDirectoryExists(dirname($directory.'/'.$reference));
        File::put($directory.'/'.$reference, $content);
    }

    foreach ([
        'Implementation/app/Services/KnowledgeService.php.md',
        'Notes/app/Services.md',
        'ADR/app/Services.md',
        'Unrelated/Hidden.md',
    ] as $reference) {
        retrievalRankingManifest($project, $reference, $sources[$reference]);
    }

    $otherProject = retrievalRankingProject('Other Ranked Knowledge Project');
    $otherDirectory = dirname($directory).'/'.Str::slug($otherProject->name);
    $otherReference = 'Implementation/app/Services/KnowledgeService.php.md';
    File::ensureDirectoryExists(dirname($otherDirectory.'/'.$otherReference));
    File::put($otherDirectory.'/'.$otherReference, 'OTHER PROJECT KNOWLEDGE');
    retrievalRankingManifest(
        $otherProject,
        $otherReference,
        'OTHER PROJECT KNOWLEDGE',
    );

    $pattern = retrievalRankingPromotePattern(
        retrievalRankingProject('Reusable Pattern Source'),
        'Ranked Retrieval Pattern',
    );

    $retrieval = app(ObsidianProjectNotes::class)
        ->taskRetrieval($task, AgentRole::Coder);

    expect(array_keys($retrieval['notes']))->toBe([
        'Task Briefs/TASK-001 - improve-ranking.md',
        'STATE.md',
        'Specifications/Explicit.md',
        'Implementation/app/Services/KnowledgeService.php.md',
        'Notes/app/Services.md',
        'ADR/app/Services.md',
    ])
        ->and(array_column($retrieval['manifest']['selected_sources'], 'ranking_reason'))->toBe([
            'current_task_brief',
            'current_state',
            'explicit_link',
            'relevant_path',
            'same_affected_area',
            'current_adr',
            'approved_global_pattern',
        ])
        ->and($retrieval['notes'])
        ->not->toHaveKey('Hidden/Recursive.md')
        ->not->toHaveKey('Unrelated/Hidden.md')
        ->and($retrieval['approved_patterns'])->toHaveCount(1)
        ->and($retrieval['approved_patterns'][0]['global_knowledge_pattern_id'])
        ->toBe($pattern->id)
        ->and($retrieval['manifest']['selected_sources'])
        ->each(fn ($source) => $source->toHaveKeys([
            'source_id',
            'source_type',
            'source_reference',
            'rank',
            'ranking_reason',
            'relationship',
            'temporal_status',
            'content_hash',
            'character_count',
        ]));

    $capsule = app(TaskContextCapsuleFactory::class)
        ->make($task, AgentRole::Coder);

    expect($capsule['approved_documentation'])->toBe($retrieval['approved_patterns']);
});

test('current manifest evidence outranks untracked and superseded sources inside the same priority tier', function (): void {
    config()->set('aios.obsidian_context_max_notes', 5);
    config()->set('aios.obsidian_context_max_characters', 20000);
    config()->set('aios.obsidian_context_max_note_characters', 4000);

    $project = retrievalRankingProject('Temporal Ranking Project');
    $directory = retrievalRankingVault($project);
    $task = Task::query()->create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Rank temporal knowledge',
        'objective' => 'Prefer current knowledge evidence.',
        'acceptance_criteria' => ['Current versions rank first.'],
        'implementation_prompt' => 'Implement temporal ranking.',
        'context_capsule' => [
            'obsidian_notes' => [
                'Notes/A-Historical.md',
                'Notes/M-Untracked.md',
                'Notes/Z-Current.md',
            ],
        ],
        'status' => TaskStatus::Coding,
    ]);

    $sources = [
        'Task Briefs/TASK-001 - rank-temporal-knowledge.md' => 'TASK BRIEF',
        'STATE.md' => 'STATE',
        'Notes/A-Historical.md' => 'HISTORICAL',
        'Notes/M-Untracked.md' => 'UNTRACKED',
        'Notes/Z-Current.md' => 'CURRENT',
    ];

    foreach ($sources as $reference => $content) {
        File::ensureDirectoryExists(dirname($directory.'/'.$reference));
        File::put($directory.'/'.$reference, $content);
    }

    retrievalRankingManifest(
        $project,
        'Notes/A-Historical.md',
        'HISTORICAL',
        current: false,
    );
    retrievalRankingManifest(
        $project,
        'Notes/Z-Current.md',
        'CURRENT',
    );

    $retrieval = app(ObsidianProjectNotes::class)
        ->taskRetrieval($task, AgentRole::Coder);

    expect(array_keys($retrieval['notes']))->toBe([
        'Task Briefs/TASK-001 - rank-temporal-knowledge.md',
        'STATE.md',
        'Notes/Z-Current.md',
        'Notes/M-Untracked.md',
        'Notes/A-Historical.md',
    ])
        ->and(array_column($retrieval['manifest']['selected_sources'], 'temporal_status'))->toBe([
            'untracked',
            'untracked',
            'current',
            'untracked',
            'superseded',
        ]);
});

test('cross project reusable retrieval includes only active approved role compatible pattern versions', function (): void {
    config()->set('aios.obsidian_context_max_notes', 10);
    config()->set('aios.obsidian_context_max_characters', 20000);
    config()->set('aios.obsidian_context_max_note_characters', 4000);

    $project = retrievalRankingProject('Pattern Consumer Project');
    $directory = retrievalRankingVault($project);
    $task = Task::query()->create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Consume approved patterns',
        'objective' => 'Consume only governed reusable guidance.',
        'acceptance_criteria' => ['Only active approved Coder patterns are included.'],
        'implementation_prompt' => 'Consume approved reusable patterns.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);

    File::ensureDirectoryExists($directory.'/Task Briefs');
    File::put(
        $directory.'/Task Briefs/TASK-001 - consume-approved-patterns.md',
        'TASK BRIEF',
    );
    File::put($directory.'/STATE.md', 'STATE');

    $sourceProject = retrievalRankingProject('Reusable Governance Source');
    $active = retrievalRankingPromotePattern(
        $sourceProject,
        'Active Coder Pattern',
    );
    $disabled = retrievalRankingPromotePattern(
        $sourceProject,
        'Disabled Coder Pattern',
    );
    $disabled->update(['enabled' => false]);
    $reviewerOnly = retrievalRankingPromotePattern(
        $sourceProject,
        'Reviewer Only Pattern',
        ['reviewer'],
    );
    $superseded = retrievalRankingPromotePattern(
        $sourceProject,
        'Versioned Coder Pattern',
    );
    $current = retrievalRankingPromotePattern(
        $sourceProject,
        'Versioned Coder Pattern',
    );

    $retrieval = app(ObsidianProjectNotes::class)
        ->taskRetrieval($task, AgentRole::Coder);
    $selectedIds = array_column(
        $retrieval['approved_patterns'],
        'global_knowledge_pattern_id',
    );
    sort($selectedIds);
    $expectedIds = [$active->id, $current->id];
    sort($expectedIds);

    expect($selectedIds)->toBe($expectedIds)
        ->and($selectedIds)->not->toContain($disabled->id)
        ->not->toContain($reviewerOnly->id)
        ->not->toContain($superseded->id)
        ->and($superseded->refresh()->superseded_at)->not->toBeNull()
        ->and($current->superseded_at)->toBeNull();
});

test('ranked retrieval applies one shared source and character quota before context budget guard', function (): void {
    config()->set('aios.obsidian_context_max_notes', 3);
    config()->set('aios.obsidian_context_max_characters', 12);
    config()->set('aios.obsidian_context_max_note_characters', 5);

    $project = retrievalRankingProject('Quota Ranking Project');
    $directory = retrievalRankingVault($project);
    $task = Task::query()->create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Preserve quotas',
        'objective' => 'Apply one strict retrieval budget.',
        'acceptance_criteria' => ['Aggregate retrieval never exceeds configured quotas.'],
        'implementation_prompt' => 'Preserve the retrieval quota.',
        'context_capsule' => [
            'obsidian_notes' => ['Specifications/Explicit.md'],
        ],
        'status' => TaskStatus::Coding,
    ]);

    File::ensureDirectoryExists($directory.'/Task Briefs');
    File::ensureDirectoryExists($directory.'/Specifications');
    File::put(
        $directory.'/Task Briefs/TASK-001 - preserve-quotas.md',
        'ABCDEFGHIJ',
    );
    File::put($directory.'/STATE.md', 'KLMNOPQRST');
    File::put($directory.'/Specifications/Explicit.md', 'UVWXYZABCD');

    retrievalRankingPromotePattern(
        retrievalRankingProject('Quota Pattern Source'),
        'Quota Pattern',
    );

    $retrieval = app(ObsidianProjectNotes::class)
        ->taskRetrieval($task, AgentRole::Coder);

    expect($retrieval['notes'])->toBe([
        'Task Briefs/TASK-001 - preserve-quotas.md' => 'ABCDE',
        'STATE.md' => 'KLMNO',
        'Specifications/Explicit.md' => 'UV',
    ])
        ->and($retrieval['approved_patterns'])->toBe([])
        ->and($retrieval['manifest']['character_count'])->toBe(12)
        ->and($retrieval['manifest']['selected_sources'])->toHaveCount(3);
});
