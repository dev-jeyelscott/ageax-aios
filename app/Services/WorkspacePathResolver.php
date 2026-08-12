<?php

namespace App\Services;

use App\Exceptions\UnsafeProjectPath;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class WorkspacePathResolver
{
    public function __construct(private Filesystem $files) {}

    public function workspaceRoot(): string
    {
        $root = (string) config('aios.workspace_root');
        $this->files->ensureDirectoryExists($root);

        return $root;
    }

    public function resolve(string $relativePath, bool $mustExist = false): string
    {
        if (Str::contains($relativePath, ['..', "\0"]) || $this->isAbsolute($relativePath)) {
            throw new UnsafeProjectPath('Project paths must be relative to the configured workspace root.');
        }

        $root = realpath($this->workspaceRoot());
        $candidate = $root.DIRECTORY_SEPARATOR.trim($relativePath, DIRECTORY_SEPARATOR);
        $resolved = $mustExist ? realpath($candidate) : $this->resolveNewPath($candidate);

        if ($resolved === false || ! Str::startsWith($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new UnsafeProjectPath('Project paths must remain inside the configured workspace root.');
        }

        return $resolved;
    }

    public function assertProjectPath(string $path): string
    {
        if (Str::contains($path, ['..', "\0"]) || ! $this->isAbsolute($path)) {
            throw new UnsafeProjectPath('Registered project paths must remain inside the configured workspace root.');
        }

        $root = realpath($this->workspaceRoot());
        $resolved = realpath($path) ?: $this->resolveNewPath($path);

        if ($root === false || ! Str::startsWith($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new UnsafeProjectPath('Registered project paths must remain inside the configured workspace root.');
        }

        return $resolved;
    }

    private function resolveNewPath(string $candidate): string
    {
        $parent = realpath(dirname($candidate));

        return $parent === false ? $candidate : $parent.DIRECTORY_SEPARATOR.basename($candidate);
    }

    private function isAbsolute(string $path): bool
    {
        return Str::startsWith($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
