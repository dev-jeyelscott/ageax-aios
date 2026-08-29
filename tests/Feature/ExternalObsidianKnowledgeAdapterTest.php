<?php

use App\Models\ExternalKnowledgeSection;
use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\ExternalObsidianKnowledgeAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function externalKnowledgeProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/ageax-external-knowledge-'.Str::uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function externalKnowledgeVault(): string
{
    $vault = storage_path(
        'framework/testing/obsidian-external-knowledge-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);

    $directory = $vault.'/External Knowledge';
    File::ensureDirectoryExists($directory);

    return $directory;
}

function externalKnowledgeNote(string $directory, string $relativePath, string $markdown, Project $project): void
{
    $path = $directory.'/'.$relativePath;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, str_replace('{project_id}', (string) $project->id, $markdown));
}

function externalKnowledgeAdapter(): ExternalObsidianKnowledgeAdapter
{
    return app(ExternalObsidianKnowledgeAdapter::class);
}

test('indexes and retrieves query-matched sections from approved project-scoped knowledge', function (): void {
    $project = externalKnowledgeProject('External Knowledge Project');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Example.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Deployment Runbook

Restart the queue worker after every deployment.

## Rollback Steps

Roll back the release tag before restoring the database.

# Unrelated Topic

Nothing about the other topics here.
MD, $project);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'rollback release');

    expect($index['index_status'])->toBe('success')
        ->and($index['indexed_sources'])->toBe(1)
        ->and($index['indexed_sections'])->toBe(3)
        ->and($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['heading'])->toBe('Rollback Steps')
        ->and($result['sections'][0]['level'])->toBe(2)
        ->and($result['sections'][0]['scope'])->toBe('project')
        ->and($result['sections'][0]['source_reference'])->toBe('Example.md')
        ->and($result['sections'][0]['content'])->toContain('Roll back the release tag')
        ->and($result['sections'][0]['content_hash'])->not->toBeEmpty()
        ->and($result['sections'][0]['knowledge_source_manifest_id'])->toBeInt()
        ->and($result['total_character_count'])->toBeGreaterThan(0);
});

test('retrieval is query scoped and never returns unrelated indexed knowledge', function (): void {
    $project = externalKnowledgeProject('Query Scoped Retrieval');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Caching.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Cache Invalidation

Flush the cache tags after a schema change.
MD, $project);

    externalKnowledgeNote($directory, 'Billing.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Invoice Numbering

Invoice sequences are generated per tenant.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'cache invalidation');

    expect($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['source_reference'])->toBe('Caching.md')
        ->and(array_column($result['sections'], 'source_reference'))->not->toContain('Billing.md');
});

test('retrieval is deterministic and ranks sections by matched term count', function (): void {
    $project = externalKnowledgeProject('Deterministic Retrieval');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Ranking.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Partial Match

This section mentions only queues.

# Full Match

This section mentions queues and retries together.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $first = externalKnowledgeAdapter()->retrieveKnowledge($project, 'queues retries');
    $second = externalKnowledgeAdapter()->retrieveKnowledge($project, 'queues retries');

    expect($first)->toEqual($second)
        ->and($first['sections'])->toHaveCount(2)
        ->and($first['sections'][0]['heading'])->toBe('Full Match')
        ->and($first['sections'][0]['matched_terms'])->toEqual(['queues', 'retries'])
        ->and($first['sections'][1]['heading'])->toBe('Partial Match');
});

test('requires a usable query before returning any external knowledge', function (): void {
    $project = externalKnowledgeProject('Query Required');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Anything.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Anything

Some approved content.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, '   ');

    expect($result['retrieval_status'])->toBe('query_required')
        ->and($result['sections'])->toHaveCount(0)
        ->and($result['total_character_count'])->toBe(0);
});

test('returns bounded absence evidence for an unavailable vault', function (): void {
    $project = externalKnowledgeProject('Unavailable Vault Project');

    config()->set('aios.obsidian_vault_path', null);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'anything');

    expect($index['index_status'])->toBe('vault_unavailable')
        ->and($index['indexed_sources'])->toBe(0)
        ->and($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(0)
        ->and($result['total_character_count'])->toBe(0);
});

test('returns bounded absence evidence when the external knowledge directory does not exist', function (): void {
    $project = externalKnowledgeProject('Missing Directory Project');
    $vault = storage_path('framework/testing/obsidian-missing-dir-'.Str::uuid());

    config()->set('aios.obsidian_vault_path', $vault);
    File::ensureDirectoryExists($vault);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);

    expect($index['index_status'])->toBe('external_knowledge_unavailable')
        ->and($index['indexed_sections'])->toBe(0);
});

test('bounds retrieved excerpts by character and section limits', function (): void {
    $project = externalKnowledgeProject('Character Limit Project');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'LongContent.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Section One

This is a very long deployment section with lots and lots of content that will definitely exceed the character limit we are testing.
This deployment section has multiple paragraphs to build up character count.

# Section Two

More deployment content here that should not be included once the limit is reached.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $bounded = externalKnowledgeAdapter()->retrieveKnowledge($project, 'deployment', null, 100);
    $singleSection = externalKnowledgeAdapter()->retrieveKnowledge($project, 'deployment', null, 5000, 1);

    expect($bounded['retrieval_status'])->toBe('success')
        ->and($bounded['total_character_count'])->toBeLessThanOrEqual(100)
        ->and($singleSection['sections'])->toHaveCount(1);
});

test('rejects markdown without valid YAML frontmatter', function (): void {
    $project = externalKnowledgeProject('No Frontmatter Project');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'NoFrontmatter.md', <<<'MD'
# Section Without Frontmatter

This markdown has no frontmatter at all.
MD, $project);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'frontmatter markdown');

    expect($index['indexed_sources'])->toBe(0)
        ->and($result['sections'])->toHaveCount(0);
});

test('rejects markdown with malformed YAML frontmatter', function (): void {
    $project = externalKnowledgeProject('Malformed YAML Project');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'BadYAML.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
invalid: [yaml: structure:
---

# Section With Bad YAML

This should be rejected.
MD, $project);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'rejected section');

    expect($index['indexed_sources'])->toBe(0)
        ->and($result['sections'])->toHaveCount(0);
});

test('rejects sections with an invalid scope', function (): void {
    $project = externalKnowledgeProject('Invalid Scope Project');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'InvalidScope.md', <<<'MD'
---
scope: invalid_scope
project_id: {project_id}
status: active
approved: true
---

# Section With Invalid Scope

This should be rejected.
MD, $project);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);

    expect($index['indexed_sources'])->toBe(0)
        ->and(externalKnowledgeAdapter()->retrieveKnowledge($project, 'rejected')['sections'])->toHaveCount(0);
});

test('rejects unapproved, inactive, and malformed approval metadata', function (string $frontmatter): void {
    $project = externalKnowledgeProject('Approval Status '.Str::uuid());
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Approval.md', <<<MD
---
scope: project
project_id: {project_id}
{$frontmatter}
---

# Unapproved Knowledge

This unapproved knowledge must never be retrieved.
MD, $project);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'unapproved knowledge');

    expect($index['indexed_sources'])->toBe(0)
        ->and($result['sections'])->toHaveCount(0);
})->with([
    'missing approval and status' => [''],
    'missing status' => ['approved: true'],
    'missing approval' => ['status: active'],
    'explicitly unapproved' => ["approved: false\nstatus: active"],
    'non-boolean approval' => ["approved: 'yes'\nstatus: active"],
    'inactive status' => ["approved: true\nstatus: draft"],
    'malformed status type' => ["approved: true\nstatus: 1"],
]);

test('indexes knowledge that is explicitly approved and active', function (): void {
    $project = externalKnowledgeProject('Approved Active Knowledge');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Approved.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Approved Knowledge

This approved knowledge is retrievable.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'approved knowledge');

    expect($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['heading'])->toBe('Approved Knowledge');
});

test('rejects project-scoped knowledge when project_id does not match', function (): void {
    $project = externalKnowledgeProject('Project Mismatch');
    $otherProject = externalKnowledgeProject('Other Project');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'MismatchedProject.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Mismatched Project Knowledge

This should not be retrieved.
MD, $otherProject);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'mismatched knowledge');

    expect($index['indexed_sources'])->toBe(0)
        ->and($result['sections'])->toHaveCount(0);
});

test('retrieves global-scoped knowledge for any project', function (): void {
    $project = externalKnowledgeProject('Global Knowledge Retrieval');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'GlobalKnowledge.md', <<<'MD'
---
scope: global
status: active
approved: true
---

# Global Knowledge

This knowledge is available to all projects.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'global knowledge');

    expect($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['heading'])->toBe('Global Knowledge')
        ->and($result['sections'][0]['scope'])->toBe('global');
});

test('retrieves agent-scoped knowledge only for the matching agent', function (): void {
    $project = externalKnowledgeProject('Agent Scoped Knowledge');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'AgentKnowledge.md', <<<'MD'
---
scope: agent
project_id: {project_id}
agent_id: 42
status: active
approved: true
---

# Agent Specific Knowledge

This knowledge is scoped to agent 42 in this project.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $withAgent = externalKnowledgeAdapter()->retrieveKnowledge($project, 'agent scoped knowledge', 42);
    $withoutAgent = externalKnowledgeAdapter()->retrieveKnowledge($project, 'agent scoped knowledge');
    $wrongAgent = externalKnowledgeAdapter()->retrieveKnowledge($project, 'agent scoped knowledge', 99);

    expect($withAgent['sections'])->toHaveCount(1)
        ->and($withAgent['sections'][0]['scope'])->toBe('agent')
        ->and($withoutAgent['sections'])->toHaveCount(0)
        ->and($wrongAgent['sections'])->toHaveCount(0);
});

test('prevents agent-scoped knowledge from leaking to another project', function (): void {
    $project1 = externalKnowledgeProject('Project 1 Agent Scope');
    $project2 = externalKnowledgeProject('Project 2 Agent Scope');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'AgentKnowledge.md', <<<'MD'
---
scope: agent
project_id: {project_id}
agent_id: 42
status: active
approved: true
---

# Agent Knowledge

This is project-specific agent knowledge.
MD, $project1);

    externalKnowledgeAdapter()->indexExternalKnowledge($project1);
    externalKnowledgeAdapter()->indexExternalKnowledge($project2);

    $resultProject1 = externalKnowledgeAdapter()->retrieveKnowledge($project1, 'agent knowledge', 42);
    $resultProject2 = externalKnowledgeAdapter()->retrieveKnowledge($project2, 'agent knowledge', 42);

    expect($resultProject1['sections'])->toHaveCount(1)
        ->and($resultProject2['sections'])->toHaveCount(0);
});

test('excludes superseded source versions after a note changes', function (): void {
    $project = externalKnowledgeProject('Superseded Knowledge');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Runbook.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Runbook

The superseded runbook instruction is obsolete.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    externalKnowledgeNote($directory, 'Runbook.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Runbook

The current runbook instruction replaces it.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'runbook instruction');

    $manifests = KnowledgeSourceManifest::query()
        ->whereBelongsTo($project)
        ->where('source_type', 'obsidian_external')
        ->where('source_reference', 'Runbook.md')
        ->get();

    expect($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['content'])->toContain('current runbook instruction')
        ->and($result['sections'][0]['content'])->not->toContain('superseded runbook instruction')
        ->and($manifests)->toHaveCount(2)
        ->and($manifests->whereNull('superseded_at'))->toHaveCount(1);
});

test('never retrieves sections whose source version was superseded', function (): void {
    $project = externalKnowledgeProject('Superseded Version Exclusion');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Current.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Current Guidance

Current retention guidance stays retrievable.
MD, $project);

    externalKnowledgeNote($directory, 'Stale.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Stale Guidance

Stale retention guidance must not be retrievable.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    KnowledgeSourceManifest::query()
        ->whereBelongsTo($project)
        ->where('source_reference', 'Stale.md')
        ->update(['superseded_at' => now()]);

    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'retention guidance');

    expect(ExternalKnowledgeSection::query()->where('source_reference', 'Stale.md')->count())->toBe(1)
        ->and($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['source_reference'])->toBe('Current.md');
});

test('removes indexed sections once a source stops being eligible', function (): void {
    $project = externalKnowledgeProject('Revoked Approval');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Revoked.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Revocable Knowledge

This revocable knowledge is approved for now.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    externalKnowledgeNote($directory, 'Revoked.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: archived
approved: true
---

# Revocable Knowledge

This revocable knowledge is no longer active.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'revocable knowledge');

    expect($result['sections'])->toHaveCount(0)
        ->and(ExternalKnowledgeSection::query()->count())->toBe(0);
});

test('rejects sources outside the external knowledge directory', function (): void {
    $project = externalKnowledgeProject('Path Traversal');
    $directory = externalKnowledgeVault();

    File::put(dirname($directory).'/Secret.md', <<<'MD'
---
scope: global
status: active
approved: true
---

# Secret

This secret content is outside the external knowledge directory.
MD);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'secret content');

    expect($index['indexed_sources'])->toBe(0)
        ->and($result['sections'])->toHaveCount(0);
});

test('indexes multiple heading levels deterministically', function (): void {
    $project = externalKnowledgeProject('Heading Levels');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'HeadingLevels.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Level 1

Heading content at level 1.

## Level 2

Heading content at level 2.

### Level 3

Heading content at level 3.

#### Level 4

Heading content at level 4.

##### Level 5

Heading content at level 5.

###### Level 6

Heading content at level 6.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $sections = ExternalKnowledgeSection::query()
        ->orderBy('position')
        ->get();

    expect($sections)->toHaveCount(6)
        ->and($sections->pluck('heading_level')->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and($sections->pluck('position')->all())->toBe([0, 1, 2, 3, 4, 5]);
});

test('skips non-markdown files in the external knowledge directory', function (): void {
    $project = externalKnowledgeProject('File Type Filtering');
    $directory = externalKnowledgeVault();

    File::put($directory.'/Document.txt', 'This is plain markdown text, not markdown.');
    File::put($directory.'/Spreadsheet.csv', 'markdown,col2,col3');

    externalKnowledgeNote($directory, 'RealMarkdown.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Markdown Section

This is indexed markdown.
MD, $project);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'markdown');

    expect($index['indexed_sources'])->toBe(1)
        ->and($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['source_reference'])->toBe('RealMarkdown.md');
});

test('indexes no sections for content without headings', function (): void {
    $project = externalKnowledgeProject('No Headings');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'NoHeadings.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

Just plain content without any headings.
More content on another line.
MD, $project);

    $index = externalKnowledgeAdapter()->indexExternalKnowledge($project);
    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'plain content');

    expect($index['indexed_sources'])->toBe(1)
        ->and($index['indexed_sections'])->toBe(0)
        ->and($result['sections'])->toHaveCount(0);
});

test('preserves stable source references across multiple files', function (): void {
    $project = externalKnowledgeProject('Multiple Files');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'File1.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# File One

Shared indexing content from file one.
MD, $project);

    externalKnowledgeNote($directory, 'Subdirectory/File2.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# File Two

Shared indexing content from file two.
MD, $project);

    externalKnowledgeAdapter()->indexExternalKnowledge($project);

    $result = externalKnowledgeAdapter()->retrieveKnowledge($project, 'shared indexing content');

    expect($result['sections'])->toHaveCount(2)
        ->and(array_column($result['sections'], 'source_reference'))
        ->toBe(['File1.md', 'Subdirectory/File2.md']);
});

test('does not mutate indexed source notes', function (): void {
    $project = externalKnowledgeProject('Read Only Sources');
    $directory = externalKnowledgeVault();

    externalKnowledgeNote($directory, 'Immutable.md', <<<'MD'
---
scope: project
project_id: {project_id}
status: active
approved: true
---

# Immutable Source

This immutable source content must never change.
MD, $project);

    $before = File::get($directory.'/Immutable.md');

    externalKnowledgeAdapter()->indexExternalKnowledge($project);
    externalKnowledgeAdapter()->retrieveKnowledge($project, 'immutable source');

    expect(File::get($directory.'/Immutable.md'))->toBe($before);
});
