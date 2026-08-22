<?php

use App\AgentRole;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\KnowledgeSourceManifestSynchronizer;
use App\Services\ObsidianProjectNotes;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Create a project rooted inside the test workspace.
 */
function knowledgeSourceManifestProject(string $name): Project
{
    $path = sys_get_temp_dir().'/ageax-knowledge-source-'.Str::uuid();

    File::ensureDirectoryExists($path);

    return Project::factory()->create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Configure and return this project's isolated Obsidian directory.
 */
function knowledgeSourceManifestVault(Project $project): string
{
    $vault = storage_path(
        'framework/testing/obsidian-knowledge-source-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);

    $directory = $vault.'/Projects/'.Str::slug($project->name);

    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * Execute one Git command inside a test project and require success.
 *
 * @param  list<string>  $command
 */
function knowledgeSourceManifestGit(
    Project $project,
    array $command,
): string {
    $result = Process::path($project->path)->run($command);

    expect($result->successful())->toBeTrue();

    return trim($result->output());
}

test('first observation stores source identity and temporal metadata without source content', function (): void {
    $project = knowledgeSourceManifestProject('Manifest Metadata');
    $directory = knowledgeSourceManifestVault($project);
    $content = 'CURRENT KNOWLEDGE CONTENT THAT MUST NOT BE STORED';

    File::put($directory.'/STATE.md', $content);

    expect(app(KnowledgeSourceManifestSynchronizer::class)->sync($project))
        ->toBe(1);

    $manifest = KnowledgeSourceManifest::query()
        ->whereBelongsTo($project)
        ->sole();

    expect($manifest->source_type)->toBe('obsidian')
        ->and($manifest->source_reference)->toBe('STATE.md')
        ->and($manifest->content_hash)->toBe(hash('sha256', $content))
        ->and($manifest->git_sha)->toBeNull()
        ->and($manifest->discovered_at)->not->toBeNull()
        ->and($manifest->last_verified_at)->not->toBeNull()
        ->and($manifest->superseded_at)->toBeNull()
        ->and($manifest->superseded_by_id)->toBeNull();

    $columns = Schema::getColumnListing('knowledge_source_manifests');

    expect($columns)->not->toContain('content')
        ->and($columns)->not->toContain('body')
        ->and($columns)->not->toContain('excerpt')
        ->and(json_encode($manifest->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain($content);
});

test('unchanged content remains one version and advances only verification time', function (): void {
    $project = knowledgeSourceManifestProject('Manifest Verification');
    $directory = knowledgeSourceManifestVault($project);

    File::put($directory.'/STATE.md', 'UNCHANGED');

    $synchronizer = app(KnowledgeSourceManifestSynchronizer::class);

    $synchronizer->sync($project);

    $manifest = KnowledgeSourceManifest::query()
        ->whereBelongsTo($project)
        ->sole();

    $discoveredAt = $manifest->discovered_at->getTimestamp();
    $firstVerifiedAt = $manifest->last_verified_at->getTimestamp();

    $this->travel(5)->minutes();

    $synchronizer->sync($project);

    $manifest->refresh();

    expect(
        KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->count(),
    )->toBe(1)
        ->and($manifest->discovered_at->getTimestamp())->toBe($discoveredAt)
        ->and($manifest->last_verified_at->getTimestamp())
        ->toBeGreaterThan($firstVerifiedAt)
        ->and($manifest->superseded_at)->toBeNull();
});

test('changed content creates a new version and preserves the superseded version', function (): void {
    $project = knowledgeSourceManifestProject('Manifest Change');
    $directory = knowledgeSourceManifestVault($project);

    File::put($directory.'/STATE.md', 'VERSION ONE');

    $synchronizer = app(KnowledgeSourceManifestSynchronizer::class);

    $synchronizer->sync($project);

    $first = KnowledgeSourceManifest::query()
        ->whereBelongsTo($project)
        ->sole();

    $this->travel(1)->minute();

    File::put($directory.'/STATE.md', 'VERSION TWO');

    $synchronizer->sync($project);

    $versions = KnowledgeSourceManifest::query()
        ->whereBelongsTo($project)
        ->orderBy('id')
        ->get();

    expect($versions)->toHaveCount(2);

    $first->refresh();
    $second = $versions->last();

    expect($first->content_hash)->toBe(hash('sha256', 'VERSION ONE'))
        ->and($first->superseded_at)->not->toBeNull()
        ->and($first->superseded_by_id)->toBe($second->id)
        ->and($second->content_hash)->toBe(hash('sha256', 'VERSION TWO'))
        ->and($second->superseded_at)->toBeNull()
        ->and($first->supersededBy?->id)->toBe($second->id)
        ->and($second->supersedes?->id)->toBe($first->id);
});

test('returning to a historical hash creates another chronological version', function (): void {
    $project = knowledgeSourceManifestProject('Manifest Reversion');
    $directory = knowledgeSourceManifestVault($project);
    $synchronizer = app(KnowledgeSourceManifestSynchronizer::class);

    File::put($directory.'/STATE.md', 'A');
    $synchronizer->sync($project);

    $this->travel(1)->minute();

    File::put($directory.'/STATE.md', 'B');
    $synchronizer->sync($project);

    $this->travel(1)->minute();

    File::put($directory.'/STATE.md', 'A');
    $synchronizer->sync($project);

    $versions = KnowledgeSourceManifest::query()
        ->whereBelongsTo($project)
        ->orderBy('id')
        ->get();

    expect($versions)->toHaveCount(3)
        ->and($versions[0]->content_hash)->toBe(hash('sha256', 'A'))
        ->and($versions[1]->content_hash)->toBe(hash('sha256', 'B'))
        ->and($versions[2]->content_hash)->toBe(hash('sha256', 'A'))
        ->and($versions[0]->id)->not->toBe($versions[2]->id)
        ->and($versions[0]->superseded_by_id)->toBe($versions[1]->id)
        ->and($versions[1]->superseded_by_id)->toBe($versions[2]->id)
        ->and($versions[2]->superseded_at)->toBeNull();
});

test('the same reference remains isolated between projects', function (): void {
    $firstProject = knowledgeSourceManifestProject('First Manifest Project');
    $vault = storage_path(
        'framework/testing/obsidian-knowledge-source-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);

    $firstDirectory = $vault.'/Projects/'.Str::slug($firstProject->name);
    File::ensureDirectoryExists($firstDirectory.'/Specifications');
    File::put(
        $firstDirectory.'/Specifications/Architecture.md',
        'FIRST PROJECT',
    );

    $secondProject = knowledgeSourceManifestProject('Second Manifest Project');
    $secondDirectory = $vault.'/Projects/'.Str::slug($secondProject->name);
    File::ensureDirectoryExists($secondDirectory.'/Specifications');
    File::put(
        $secondDirectory.'/Specifications/Architecture.md',
        'SECOND PROJECT',
    );

    $synchronizer = app(KnowledgeSourceManifestSynchronizer::class);

    $synchronizer->sync($firstProject);
    $synchronizer->sync($secondProject);

    $first = KnowledgeSourceManifest::query()
        ->whereBelongsTo($firstProject)
        ->where('source_reference', 'Specifications/Architecture.md')
        ->sole();

    $second = KnowledgeSourceManifest::query()
        ->whereBelongsTo($secondProject)
        ->where('source_reference', 'Specifications/Architecture.md')
        ->sole();

    expect($first->project_id)->not->toBe($second->project_id)
        ->and($first->content_hash)->toBe(hash('sha256', 'FIRST PROJECT'))
        ->and($second->content_hash)->toBe(hash('sha256', 'SECOND PROJECT'));
});

test('repository source records a git sha only for clean committed content', function (): void {
    $project = knowledgeSourceManifestProject('Repository Manifest');

    File::ensureDirectoryExists($project->path.'/docs');
    File::put(
        $project->path.'/docs/Knowledge.md',
        'COMMITTED KNOWLEDGE',
    );

    knowledgeSourceManifestGit($project, ['git', 'init', '--quiet']);
    knowledgeSourceManifestGit(
        $project,
        ['git', 'config', 'user.email', 'ageax-test@example.com'],
    );
    knowledgeSourceManifestGit(
        $project,
        ['git', 'config', 'user.name', 'AGEAX Test'],
    );
    knowledgeSourceManifestGit(
        $project,
        ['git', 'add', 'docs/Knowledge.md'],
    );
    knowledgeSourceManifestGit(
        $project,
        ['git', 'commit', '--quiet', '-m', 'Add knowledge source'],
    );

    $head = knowledgeSourceManifestGit(
        $project,
        ['git', 'rev-parse', 'HEAD'],
    );

    $manifest = app(KnowledgeSourceManifestSynchronizer::class)
        ->trackRepositoryFile($project, 'docs/Knowledge.md');

    expect($manifest)->not->toBeNull()
        ->and($manifest?->source_type)->toBe('repository')
        ->and($manifest?->source_reference)->toBe('docs/Knowledge.md')
        ->and($manifest?->git_sha)->toBe($head)
        ->and($manifest?->content_hash)
        ->toBe(hash('sha256', 'COMMITTED KNOWLEDGE'));
});

test('sources outside the project obsidian directory are not indexed', function (): void {
    $project = knowledgeSourceManifestProject('Manifest Boundary');
    $vault = storage_path(
        'framework/testing/obsidian-knowledge-source-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);

    $projectDirectory = $vault.'/Projects/'.Str::slug($project->name);

    File::ensureDirectoryExists($projectDirectory);
    File::put($projectDirectory.'/Inside.md', 'INSIDE');
    File::put($vault.'/Outside.md', 'OUTSIDE');

    $otherDirectory = $vault.'/Projects/other-project';
    File::ensureDirectoryExists($otherDirectory);
    File::put($otherDirectory.'/Other.md', 'OTHER');

    app(KnowledgeSourceManifestSynchronizer::class)->sync($project);

    $references = KnowledgeSourceManifest::query()
        ->whereBelongsTo($project)
        ->pluck('source_reference')
        ->all();

    expect($references)->toBe(['Inside.md']);
});

test('an unavailable vault does not corrupt or supersede existing evidence', function (): void {
    $project = knowledgeSourceManifestProject('Unavailable Manifest Vault');

    $existing = KnowledgeSourceManifest::factory()
        ->for($project)
        ->create([
            'source_reference' => 'STATE.md',
            'content_hash' => hash('sha256', 'EXISTING'),
        ]);

    config()->set('aios.obsidian_vault_path', null);

    $synchronizer = app(KnowledgeSourceManifestSynchronizer::class);

    expect($synchronizer->sync($project))->toBe(0)
        ->and($existing->refresh()->superseded_at)->toBeNull()
        ->and($existing->superseded_by_id)->toBeNull();

    $unavailableVault = storage_path(
        'framework/testing/unavailable-vault-'.Str::uuid(),
    );

    File::put($unavailableVault, 'not a directory');

    config()->set('aios.obsidian_vault_path', $unavailableVault);

    expect($synchronizer->sync($project))->toBe(0)
        ->and($existing->refresh()->superseded_at)->toBeNull()
        ->and($existing->superseded_by_id)->toBeNull();
});

test('manifest synchronization does not broaden bounded agent retrieval', function (): void {
    $project = knowledgeSourceManifestProject('Bounded Retrieval Manifest');
    $directory = knowledgeSourceManifestVault($project);

    $task = Task::query()->create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Bounded source retrieval',
        'objective' => 'Keep Agent knowledge retrieval targeted.',
        'acceptance_criteria' => ['Only intentional notes are retrieved.'],
        'implementation_prompt' => 'Preserve bounded retrieval.',
        'context_capsule' => [
            'obsidian_notes' => ['Specifications/Intent.md'],
        ],
        'status' => TaskStatus::Coding,
    ]);

    File::ensureDirectoryExists($directory.'/Task Briefs');
    File::ensureDirectoryExists($directory.'/Specifications');
    File::ensureDirectoryExists($directory.'/Unrelated');

    File::put($directory.'/STATE.md', 'CURRENT STATE');
    File::put(
        $directory.'/Task Briefs/TASK-001 - bounded-source-retrieval.md',
        'CURRENT TASK BRIEF',
    );
    File::put(
        $directory.'/Specifications/Intent.md',
        'INTENTIONAL KNOWLEDGE',
    );
    File::put(
        $directory.'/Unrelated/Hidden.md',
        'INDEXED BUT NOT RETRIEVED',
    );

    app(KnowledgeSourceManifestSynchronizer::class)->sync($project);

    $retrieval = app(ObsidianProjectNotes::class)
        ->taskRetrieval($task, AgentRole::Coder);

    expect(
        KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->count(),
    )->toBe(4)
        ->and(array_keys($retrieval['notes']))->toBe([
            'STATE.md',
            'Task Briefs/TASK-001 - bounded-source-retrieval.md',
            'Specifications/Intent.md',
        ])
        ->and($retrieval['notes'])
        ->not->toHaveKey('Unrelated/Hidden.md');
});

test('the existing knowledge scan synchronizes manifests without creating candidates from them', function (): void {
    $project = knowledgeSourceManifestProject('Integrated Manifest Scan');
    $directory = knowledgeSourceManifestVault($project);

    File::put($directory.'/STATE.md', 'CURRENT PROJECT KNOWLEDGE');

    $this->artisan('aios:knowledge-improvements:scan', [
        '--project' => (string) $project->id,
    ])->assertSuccessful();

    expect(
        KnowledgeSourceManifest::query()
            ->whereBelongsTo($project)
            ->count(),
    )->toBe(1)
        ->and(
            KnowledgeImprovementCandidate::query()
                ->whereBelongsTo($project)
                ->exists(),
        )->toBeFalse();
});
