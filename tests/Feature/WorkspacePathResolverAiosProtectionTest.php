<?php

use App\Exceptions\UnsafeProjectPath;
use App\Services\WorkspacePathResolver;

test('resolve() rejects the AIOS installation itself', function () {
    config()->set('aios.workspace_root', dirname(base_path()));

    expect(fn () => app(WorkspacePathResolver::class)->resolve(basename(base_path()), true))
        ->toThrow(UnsafeProjectPath::class);
});

test('resolve() rejects a path inside the AIOS installation', function () {
    config()->set('aios.workspace_root', base_path());

    expect(fn () => app(WorkspacePathResolver::class)->resolve('app', true))
        ->toThrow(UnsafeProjectPath::class);
});

test('resolve() rejects an ancestor of the AIOS installation', function () {
    config()->set('aios.workspace_root', dirname(base_path(), 2));

    expect(fn () => app(WorkspacePathResolver::class)->resolve(basename(dirname(base_path())), true))
        ->toThrow(UnsafeProjectPath::class);
});

test('assertProjectPath() rejects a stale persisted path pointing at the AIOS installation', function () {
    expect(fn () => app(WorkspacePathResolver::class)->assertProjectPath(base_path()))
        ->toThrow(UnsafeProjectPath::class);
});

test('assertProjectPath() rejects a stale persisted path inside the AIOS installation', function () {
    expect(fn () => app(WorkspacePathResolver::class)->assertProjectPath(base_path('app')))
        ->toThrow(UnsafeProjectPath::class);
});

test('assertProjectPath() still accepts a safe path outside the AIOS installation', function () {
    $safe = sys_get_temp_dir().'/aios-safe-project-'.uniqid();
    mkdir($safe, 0755, true);

    try {
        expect(app(WorkspacePathResolver::class)->assertProjectPath($safe))->toBe(realpath($safe));
    } finally {
        rmdir($safe);
    }
});
