<?php

use App\Models\Project;
use App\ProjectStatus;
use App\Services\TargetedRepositoryRetrieval;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Create a project rooted inside the test workspace.
 */
function targetedRetrievalProject(string $name): Project
{
    $path = sys_get_temp_dir().'/ageax-targeted-retrieval-'.Str::uuid();

    File::ensureDirectoryExists($path);

    return Project::factory()->create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Initialize a Git repository in a test project.
 *
 * @param  list<string>  $command
 */
function targetedRetrievalGit(Project $project, array $command): string
{
    $result = Process::path($project->path)->run($command);

    expect($result->successful())->toBeTrue();

    return trim($result->output());
}

test('targeted retrieval respects bounded file limits', function (): void {
    $project = targetedRetrievalProject('Bounded Retrieval');

    File::ensureDirectoryExists($project->path.'/docs');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);

    for ($i = 1; $i <= 150; $i++) {
        File::put($project->path.'/docs/doc-'.$i.'.md', "Document $i content");
        targetedRetrievalGit($project, ['git', 'add', 'docs/doc-'.$i.'.md']);
    }

    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add docs']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['default_search_dirs' => ['docs']],
    );

    expect($result['files'])->toHaveCount(100)
        ->and(count($result['files']))->toBeLessThanOrEqual(100)
        ->and($result['repository_revision'])->not->toBeNull()
        ->and($result['selection_reason'])->toContain('deterministic_targeted_discovery');
});

test('targeted retrieval includes selection reasons for each file', function (): void {
    $project = targetedRetrievalProject('Selection Reasons');

    File::ensureDirectoryExists($project->path.'/docs');
    File::put($project->path.'/docs/README.md', 'Main documentation');
    File::put($project->path.'/docs/API.md', 'API specification');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', '.']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Initial']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        [
            'explicit_paths' => ['docs/README.md'],
            'changed_files' => ['docs/API.md'],
        ],
    );

    $reasons = array_column($result['files'], 'reason');

    expect($result['files'])->toHaveCount(2)
        ->and($reasons)->toContain('explicit_path_from_discovery_input')
        ->and($reasons)->toContain('changed_file_from_git_metadata');
});

test('targeted retrieval attaches git sha provenance for clean repositories', function (): void {
    $project = targetedRetrievalProject('Git Provenance');

    File::put($project->path.'/knowledge.md', 'Knowledge content');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'knowledge.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add knowledge']);

    $expectedSha = targetedRetrievalGit($project, ['git', 'rev-parse', 'HEAD']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['knowledge.md']],
    );

    expect($result['files'])->toHaveCount(1)
        ->and($result['files'][0]['git_sha'])->toBe($expectedSha)
        ->and($result['repository_revision'])->toBe($expectedSha);
});

test('targeted retrieval excludes .env files', function (): void {
    $project = targetedRetrievalProject('Env Exclusion');

    File::ensureDirectoryExists($project->path.'/docs');
    File::put($project->path.'/.env', 'SENSITIVE_TOKEN=secret123');
    File::put($project->path.'/docs/config.md', 'Configuration guide');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'docs/config.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add config']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['.env', 'docs/config.md']],
    );

    $paths = array_column($result['files'], 'path');

    expect($paths)->toContain('docs/config.md')
        ->and($paths)->not->toContain('.env');
});

test('targeted retrieval excludes files with secret material', function (): void {
    $project = targetedRetrievalProject('Secret Exclusion');

    File::put(
        $project->path.'/config.md',
        'database_password = very_secret_password_123',
    );
    File::put(
        $project->path.'/api.md',
        'api_key: sk-1234567890abcdefghij1234',
    );
    File::put(
        $project->path.'/docs.md',
        'Normal documentation content',
    );

    File::ensureDirectoryExists($project->path);

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'docs.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add docs']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['config.md', 'api.md', 'docs.md']],
    );

    $paths = array_column($result['files'], 'path');

    expect($paths)->toContain('docs.md')
        ->and($paths)->not->toContain('config.md')
        ->and($paths)->not->toContain('api.md');
});

test('targeted retrieval excludes git directory and other dangerous paths', function (): void {
    $project = targetedRetrievalProject('Path Exclusion');

    File::put($project->path.'/normal.md', 'Normal file');
    File::ensureDirectoryExists($project->path.'/vendor');
    File::put($project->path.'/vendor/unsafe.md', 'Vendor file');
    File::ensureDirectoryExists($project->path.'/node_modules');
    File::put($project->path.'/node_modules/lib.md', 'Node module');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'normal.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add normal']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['default_search_dirs' => ['.', 'vendor', 'node_modules']],
    );

    $paths = array_column($result['files'], 'path');

    expect($paths)->toContain('normal.md')
        ->and($paths)->not->toContain('vendor/unsafe.md')
        ->and($paths)->not->toContain('node_modules/lib.md');
});

test('targeted retrieval rejects unsafe path traversal', function (): void {
    $project = targetedRetrievalProject('Path Traversal');

    File::put($project->path.'/doc.md', 'Document');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'doc.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add doc']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['../../../etc/passwd', 'doc.md']],
    );

    $paths = array_column($result['files'], 'path');

    expect($paths)->toContain('doc.md')
        ->and($paths)->not->toContain('../../../etc/passwd');
});

test('targeted retrieval handles non-git project gracefully', function (): void {
    $project = targetedRetrievalProject('Non-Git Project');

    File::put($project->path.'/doc.md', 'Document');

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['doc.md']],
    );

    expect($result['files'])->toHaveCount(0)
        ->and($result['repository_revision'])->toBeNull()
        ->and($result['selection_reason'])->toContain('not inspectable');
});

test('targeted retrieval searches by task terms in filenames', function (): void {
    $project = targetedRetrievalProject('Task Term Search');

    File::ensureDirectoryExists($project->path.'/docs');
    File::put($project->path.'/docs/user-guide.md', 'User guide content');
    File::put($project->path.'/docs/admin-guide.md', 'Admin guide content');
    File::put($project->path.'/docs/api-reference.md', 'API reference');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', '.']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add docs']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['default_search_dirs' => ['docs']],
    );

    $paths = array_column($result['files'], 'path');

    expect($result['files'])->not->toHaveCount(0)
        ->and(count($paths))->toBeLessThanOrEqual(100);
});

test('targeted retrieval uses workspace path resolver safety', function (): void {
    $workspaceRoot = sys_get_temp_dir()
        .'/ageax-targeted-retrieval-workspace-'
        .Str::uuid();

    File::ensureDirectoryExists($workspaceRoot);
    config()->set('aios.workspace_root', $workspaceRoot);

    $path = $workspaceRoot.'/test-project-'.Str::uuid();
    File::ensureDirectoryExists($path);

    $outside = sys_get_temp_dir()
        .'/ageax-targeted-retrieval-outside-'
        .Str::uuid();

    File::ensureDirectoryExists($outside);

    $project = Project::factory()->create([
        'name' => 'Path Resolver Test',
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    expect(
        fn () => app(TargetedRepositoryRetrieval::class)->retrieve(
            $project,
            ['explicit_paths' => ['../../../etc/passwd']],
        ),
    )->not->toThrow(Exception::class);
});

test('targeted retrieval returns provenance with clean repository state', function (): void {
    $project = targetedRetrievalProject('Provenance Check');

    File::put($project->path.'/status.md', 'Status information');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'status.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add status']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['status.md']],
    );

    expect($result)->toHaveKeys(['files', 'repository_revision', 'repository_state', 'selection_reason'])
        ->and($result['repository_revision'])->not->toBeNull()
        ->and($result['repository_state']['state'])->toBe('clean')
        ->and($result['repository_state']['clean'])->toBeTrue()
        ->and($result['files'][0])->toHaveKeys([
            'path', 'reason', 'git_sha', 'excerpt', 'excerpt_line_count', 'excerpt_truncated', 'symbols',
        ]);
});

test('targeted retrieval reports revision provenance for clean and dirty worktrees', function (): void {
    $project = targetedRetrievalProject('Dirty Repository');

    File::put($project->path.'/file.md', 'File content');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'file.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add file']);

    $headSha = targetedRetrievalGit($project, ['git', 'rev-parse', 'HEAD']);

    $cleanResult = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['file.md']],
    );

    expect($cleanResult['files'][0]['git_sha'])->toBe($headSha)
        ->and($cleanResult['repository_revision'])->toBe($headSha)
        ->and($cleanResult['repository_state'])->toMatchArray([
            'state' => 'clean',
            'clean' => true,
            'head_sha' => $headSha,
        ]);

    File::put($project->path.'/file.md', 'Modified content');

    $dirtyResult = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['file.md']],
    );

    expect($dirtyResult['files'][0]['git_sha'])->toBe($headSha)
        ->and($dirtyResult['repository_revision'])->toBe($headSha)
        ->and($dirtyResult['repository_state'])->toMatchArray([
            'state' => 'dirty',
            'clean' => false,
            'head_sha' => $headSha,
        ])
        ->and($dirtyResult['files'][0]['excerpt'])->toBe('Modified content');
});

test('targeted retrieval returns bounded excerpts for selected files', function (): void {
    $project = targetedRetrievalProject('Bounded Excerpts');

    File::put($project->path.'/short.md', "Line one\nLine two");
    File::put($project->path.'/long.md', implode("\n", array_map(
        fn (int $line): string => 'Line '.$line,
        range(1, 200),
    )));

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', '.']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add files']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['short.md', 'long.md']],
    );

    $excerpts = collect($result['files'])->keyBy('path');

    expect($excerpts['short.md']['excerpt'])->toBe("Line one\nLine two")
        ->and($excerpts['short.md']['excerpt_line_count'])->toBe(2)
        ->and($excerpts['short.md']['excerpt_truncated'])->toBeFalse()
        ->and($excerpts['long.md']['excerpt_line_count'])->toBe(40)
        ->and($excerpts['long.md']['excerpt_truncated'])->toBeTrue()
        ->and($excerpts['long.md']['excerpt'])->toStartWith('Line 1')
        ->and($excerpts['long.md']['excerpt'])->not->toContain('Line 41');
});

test('targeted retrieval returns symbols where the file type supports them', function (): void {
    $project = targetedRetrievalProject('Symbol Support');

    File::put($project->path.'/notes.md', "# Overview\n\nBody\n\n## Details\n");
    File::put($project->path.'/Sample.php', "<?php\n\nnamespace App;\n\nfinal class Sample\n{\n}\n\ninterface SampleContract\n{\n}\n");
    File::put($project->path.'/notes.txt', 'Plain text without symbol support');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', '.']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add sources']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        ['explicit_paths' => ['notes.md', 'Sample.php', 'notes.txt']],
    );

    $files = collect($result['files'])->keyBy('path');

    expect($files['notes.md']['symbols'])->toBe(['heading Overview', 'heading Details'])
        ->and($files['Sample.php']['symbols'])->toBe(['class Sample', 'interface SampleContract'])
        ->and($files['notes.txt']['symbols'])->toBe([]);
});

test('targeted retrieval rejects traversal through default search dirs', function (): void {
    $project = targetedRetrievalProject('Search Dir Traversal');

    File::put($project->path.'/doc.md', 'Document');

    $outside = dirname($project->path).'/ageax-targeted-retrieval-outside-'.Str::uuid();
    File::ensureDirectoryExists($outside);
    File::put($outside.'/leaked.md', 'Outside document');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'doc.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add doc']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        [
            'default_search_dirs' => [
                '../'.basename($outside),
                '../../'.basename(dirname($outside)),
                $outside,
            ],
        ],
    );

    $paths = array_column($result['files'], 'path');

    expect($result['files'])->toHaveCount(0)
        ->and($paths)->not->toContain('leaked.md');

    File::deleteDirectory($outside);
});

test('targeted retrieval rejects symlinked search dirs that escape the repository', function (): void {
    $project = targetedRetrievalProject('Symlink Escape');

    File::ensureDirectoryExists($project->path.'/docs');
    File::put($project->path.'/docs/inside.md', 'Inside document');

    $outside = dirname($project->path).'/ageax-targeted-retrieval-symlink-'.Str::uuid();
    File::ensureDirectoryExists($outside);
    File::put($outside.'/leaked.md', 'Outside document');

    symlink($outside, $project->path.'/escape');
    symlink($outside, $project->path.'/docs/escape');

    targetedRetrievalGit($project, ['git', 'init', '--quiet']);
    targetedRetrievalGit($project, ['git', 'config', 'user.email', 'test@example.com']);
    targetedRetrievalGit($project, ['git', 'config', 'user.name', 'Test User']);
    targetedRetrievalGit($project, ['git', 'add', 'docs/inside.md']);
    targetedRetrievalGit($project, ['git', 'commit', '--quiet', '-m', 'Add inside']);

    $result = app(TargetedRepositoryRetrieval::class)->retrieve(
        $project,
        [
            'default_search_dirs' => ['escape', 'docs'],
            'task_terms' => ['leaked', 'inside'],
        ],
    );

    $paths = array_column($result['files'], 'path');

    expect($paths)->toContain('docs/inside.md')
        ->and($paths)->not->toContain('escape/leaked.md')
        ->and($paths)->not->toContain('docs/escape/leaked.md');

    File::deleteDirectory($outside);
});
