<?php

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

function externalKnowledgeVault(Project $project): string
{
    $vault = storage_path(
        'framework/testing/obsidian-external-knowledge-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);

    $directory = $vault.'/External Knowledge';
    File::ensureDirectoryExists($directory);

    return $directory;
}

test('retrieves sections from markdown with valid project-scoped frontmatter', function (): void {
    $project = externalKnowledgeProject('External Knowledge Project');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: project
project_id: {project_id}
approved: true
---

# First Section

Content of the first section goes here.

## Subsection

Content of subsection.

# Second Section

Content of the second section.
MD;

    File::put($directory.'/Example.md', str_replace('{project_id}', (string) $project->id, $markdown));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(3)
        ->and($result['sections'][0]['heading'])->toBe('First Section')
        ->and($result['sections'][0]['level'])->toBe(1)
        ->and($result['sections'][0]['source_reference'])->toBe('Example.md')
        ->and($result['sections'][1]['heading'])->toBe('Subsection')
        ->and($result['sections'][1]['level'])->toBe(2)
        ->and($result['sections'][2]['heading'])->toBe('Second Section')
        ->and($result['total_character_count'])->toBeGreaterThan(0);
});

test('returns empty results for unavailable vault', function (): void {
    $project = externalKnowledgeProject('Unavailable Vault Project');

    config()->set('aios.obsidian_vault_path', null);

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('vault_unavailable')
        ->and($result['sections'])->toHaveCount(0)
        ->and($result['total_character_count'])->toBe(0);
});

test('returns empty results when external knowledge directory does not exist', function (): void {
    $project = externalKnowledgeProject('Missing Directory Project');
    $vault = storage_path(
        'framework/testing/obsidian-missing-dir-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);
    File::ensureDirectoryExists($vault);

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('external_knowledge_unavailable')
        ->and($result['sections'])->toHaveCount(0);
});

test('respects character limit and truncates sections', function (): void {
    $project = externalKnowledgeProject('Character Limit Project');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: project
project_id: {project_id}
---

# Section One

This is a very long section with lots and lots of content that will definitely exceed the character limit we are testing.
This section has multiple paragraphs to build up character count.

# Section Two

More content here that should not be included if we hit the limit.
MD;

    File::put($directory.'/LongContent.md', str_replace('{project_id}', (string) $project->id, $markdown));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project, null, 100);

    expect($result['retrieval_status'])->toBe('success')
        ->and($result['total_character_count'])->toBeLessThanOrEqual(100);
});

test('rejects markdown without valid YAML frontmatter', function (): void {
    $project = externalKnowledgeProject('No Frontmatter Project');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
# Section Without Frontmatter

This markdown has no frontmatter at all.
MD;

    File::put($directory.'/NoFrontmatter.md', $markdown);

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(0);
});

test('rejects markdown with malformed YAML frontmatter', function (): void {
    $project = externalKnowledgeProject('Malformed YAML Project');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: project
project_id: {project_id}
invalid: [yaml: structure:
---

# Section With Bad YAML

This should be rejected.
MD;

    File::put($directory.'/BadYAML.md', str_replace('{project_id}', (string) $project->id, $markdown));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(0);
});

test('rejects sections with invalid scope', function (): void {
    $project = externalKnowledgeProject('Invalid Scope Project');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: invalid_scope
project_id: {project_id}
---

# Section With Invalid Scope

This should be rejected.
MD;

    File::put($directory.'/InvalidScope.md', str_replace('{project_id}', (string) $project->id, $markdown));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(0);
});

test('rejects project-scoped knowledge when project_id does not match', function (): void {
    $project = externalKnowledgeProject('Project Mismatch');
    $otherProject = externalKnowledgeProject('Other Project');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: project
project_id: {project_id}
---

# Mismatched Project Knowledge

This should not be retrieved.
MD;

    File::put($directory.'/MismatchedProject.md', str_replace('{project_id}', (string) $otherProject->id, $markdown));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(0);
});

test('retrieves global-scoped knowledge for any project', function (): void {
    $project = externalKnowledgeProject('Global Knowledge Retrieval');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: global
approved: true
---

# Global Knowledge

This knowledge is available to all projects.
MD;

    File::put($directory.'/GlobalKnowledge.md', $markdown);

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['heading'])->toBe('Global Knowledge');
});

test('retrieves agent-scoped knowledge only for matching agent and project', function (): void {
    $project = externalKnowledgeProject('Agent Scoped Knowledge');
    $agentId = 42;
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: agent
project_id: {project_id}
agent_id: 42
---

# Agent-Specific Knowledge

This knowledge is scoped to agent 42 in this project.
MD;

    File::put($directory.'/AgentKnowledge.md', str_replace('{project_id}', (string) $project->id, $markdown));

    $resultWithAgent = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project, $agentId);
    $resultWithoutAgent = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);
    $resultWrongAgent = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project, 99);

    expect($resultWithAgent['sections'])->toHaveCount(1)
        ->and($resultWithoutAgent['sections'])->toHaveCount(0)
        ->and($resultWrongAgent['sections'])->toHaveCount(0);
});

test('prevents agent-scoped knowledge leakage to different projects', function (): void {
    $project1 = externalKnowledgeProject('Project 1 Agent Scope');
    $project2 = externalKnowledgeProject('Project 2 Agent Scope');
    $agentId = 42;

    $vault = storage_path(
        'framework/testing/obsidian-agent-scope-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);

    $directory = $vault.'/External Knowledge';
    File::ensureDirectoryExists($directory);

    $markdown = <<<'MD'
---
scope: agent
project_id: {project_id}
agent_id: 42
---

# Agent Knowledge

This is project-specific agent knowledge.
MD;

    File::put($directory.'/AgentKnowledge.md', str_replace('{project_id}', (string) $project1->id, $markdown));

    $resultProject1 = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project1, $agentId);
    $resultProject2 = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project2, $agentId);

    expect($resultProject1['sections'])->toHaveCount(1)
        ->and($resultProject2['sections'])->toHaveCount(0);
});

test('rejects paths with directory traversal attempts', function (): void {
    $project = externalKnowledgeProject('Path Traversal');
    $vault = storage_path(
        'framework/testing/obsidian-traversal-'.Str::uuid(),
    );

    config()->set('aios.obsidian_vault_path', $vault);

    $directory = $vault.'/External Knowledge';
    File::ensureDirectoryExists($directory);

    $parentDirectory = $vault;
    File::ensureDirectoryExists($parentDirectory);

    File::put($parentDirectory.'/Secret.md', 'SHOULD NOT BE ACCESSIBLE');

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['sections'])->toHaveCount(0);
});

test('parses multiple heading levels deterministically', function (): void {
    $project = externalKnowledgeProject('Heading Levels');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: project
project_id: {project_id}
---

# Level 1

Content at level 1.

## Level 2

Content at level 2.

### Level 3

Content at level 3.

#### Level 4

Content at level 4.

##### Level 5

Content at level 5.

###### Level 6

Content at level 6.
MD;

    File::put($directory.'/HeadingLevels.md', str_replace('{project_id}', (string) $project->id, $markdown));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['sections'])->toHaveCount(6)
        ->and($result['sections'][0]['level'])->toBe(1)
        ->and($result['sections'][1]['level'])->toBe(2)
        ->and($result['sections'][2]['level'])->toBe(3)
        ->and($result['sections'][3]['level'])->toBe(4)
        ->and($result['sections'][4]['level'])->toBe(5)
        ->and($result['sections'][5]['level'])->toBe(6);
});

test('skips non-markdown files in external knowledge directory', function (): void {
    $project = externalKnowledgeProject('File Type Filtering');
    $directory = externalKnowledgeVault($project);

    File::put($directory.'/Document.txt', 'This is plain text, not markdown.');
    File::put($directory.'/Spreadsheet.csv', 'col1,col2,col3');

    $markdown = <<<'MD'
---
scope: project
project_id: {project_id}
---

# Markdown Section

This is markdown.
MD;

    File::put($directory.'/RealMarkdown.md', str_replace('{project_id}', (string) $project->id, $markdown));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0]['source_reference'])->toBe('RealMarkdown.md');
});

test('handles sections without headings gracefully', function (): void {
    $project = externalKnowledgeProject('No Headings');
    $directory = externalKnowledgeVault($project);

    $markdown = <<<'MD'
---
scope: project
project_id: {project_id}
---

Just plain content without any headings.
More content on another line.
MD;

    File::put($directory.'/NoHeadings.md', str_replace('{project_id}', (string) $project->id, $markdown));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['retrieval_status'])->toBe('success')
        ->and($result['sections'])->toHaveCount(0);
});

test('processes multiple files and preserves source references', function (): void {
    $project = externalKnowledgeProject('Multiple Files');
    $directory = externalKnowledgeVault($project);

    File::ensureDirectoryExists($directory.'/Subdirectory');

    $markdown1 = <<<'MD'
---
scope: project
project_id: {project_id}
---

# File One

Content from file one.
MD;

    $markdown2 = <<<'MD'
---
scope: project
project_id: {project_id}
---

# File Two

Content from file two.
MD;

    File::put($directory.'/File1.md', str_replace('{project_id}', (string) $project->id, $markdown1));
    File::put($directory.'/Subdirectory/File2.md', str_replace('{project_id}', (string) $project->id, $markdown2));

    $result = app(ExternalObsidianKnowledgeAdapter::class)->retrieveKnowledge($project);

    expect($result['sections'])->toHaveCount(2)
        ->and(array_column($result['sections'], 'source_reference'))->toContain('File1.md')
        ->and(array_column($result['sections'], 'source_reference'))->toContain('Subdirectory/File2.md');
});
