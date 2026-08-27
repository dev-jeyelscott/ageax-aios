<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/** Maintains deterministic navigation metadata inside one project-scoped Obsidian root. */
class ObsidianKnowledgeTopologySynchronizer
{
    private const string TopologyStart = '<!-- AIOS:BEGIN TOPOLOGY -->';

    private const string TopologyEnd = '<!-- AIOS:END TOPOLOGY -->';

    private const string NavigationStart = '<!-- AIOS:BEGIN NAVIGATION -->';

    private const string NavigationEnd = '<!-- AIOS:END NAVIGATION -->';

    private const string BacklinksStart = '<!-- AIOS:BEGIN BACKLINKS -->';

    private const string BacklinksEnd = '<!-- AIOS:END BACKLINKS -->';

    public function __construct(private Filesystem $files) {}

    /** @return array{created: list<string>, changed: list<array{path: string, sections: list<string>}>, unchanged: list<string>} */
    public function sync(Project $project): array
    {
        $root = $this->projectDirectory($project, create: true);

        if ($root === null) {
            return ['created' => [], 'changed' => [], 'unchanged' => []];
        }

        $notes = $this->markdownNotes($root);
        $changes = ['created' => [], 'changed' => [], 'unchanged' => []];

        foreach ($this->foldersFor($notes) as $folder) {
            $this->synchronizeFolder($root, $folder, $notes, $changes);
        }

        $notes = $this->markdownNotes($root);

        foreach ($notes as $reference => $path) {
            if ($this->isTopologyFile($reference)) {
                continue;
            }

            $content = $this->files->get($path);
            $updated = $this->replaceSection($content, self::BacklinksStart, self::BacklinksEnd, $this->backlinksSection($this->explicitBacklinks($notes)[$reference] ?? []));
            $this->recordWrite($root, $reference, $path, $content, $updated, ['backlinks'], $changes);
        }

        sort($changes['created'], SORT_STRING);
        usort($changes['changed'], fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        sort($changes['unchanged'], SORT_STRING);

        return $changes;
    }

    /**
     * @param  array<string, string>  $notes
     * @param  array{created: list<string>, changed: list<array{path: string, sections: list<string>}>, unchanged: list<string>}  $changes
     */
    private function synchronizeFolder(string $root, string $folder, array $notes, array &$changes): void
    {
        $prefix = $folder === '' ? '' : $folder.'/';
        $folderPath = $folder === '' ? $root : $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $folder);
        $index = $prefix.'index.md';
        $agents = $prefix.'AGENTS.md';
        $children = [];
        $childFolders = [];

        foreach (array_keys($notes) as $reference) {
            if (($folder !== '' && ! Str::startsWith($reference, $prefix)) || in_array($reference, [$index, $agents], true)) {
                continue;
            }

            $remaining = Str::after($reference, $prefix);

            if (Str::contains($remaining, '/')) {
                $childFolders[] = $prefix.Str::before($remaining, '/');
            } else {
                $children[] = $reference;
            }
        }

        $children = array_values(array_unique($children));
        $childFolders = array_values(array_unique($childFolders));
        sort($children, SORT_STRING);
        sort($childFolders, SORT_STRING);

        $indexPath = $folderPath.DIRECTORY_SEPARATOR.'index.md';
        $indexContent = $this->files->isFile($indexPath) ? $this->files->get($indexPath) : '';
        $this->recordWrite($root, $index, $indexPath, $indexContent, $this->replaceSection($indexContent, self::TopologyStart, self::TopologyEnd, $this->indexSection($folder, $agents, $children, $childFolders)), ['topology'], $changes);

        $agentsPath = $folderPath.DIRECTORY_SEPARATOR.'AGENTS.md';
        $agentsContent = $this->files->isFile($agentsPath) ? $this->files->get($agentsPath) : '';
        $this->recordWrite($root, $agents, $agentsPath, $agentsContent, $this->replaceSection($agentsContent, self::TopologyStart, self::TopologyEnd, $this->agentsSection($folder, $index)), ['topology'], $changes);

        foreach ($children as $reference) {
            $path = $notes[$reference];
            $content = $this->files->get($path);
            $this->recordWrite($root, $reference, $path, $content, $this->replaceSection($content, self::NavigationStart, self::NavigationEnd, $this->navigationSection($index)), ['navigation'], $changes);
        }
    }

    /**
     * @param  list<string>  $children
     * @param  list<string>  $childFolders
     */
    private function indexSection(string $folder, string $agents, array $children, array $childFolders): string
    {
        $lines = [self::TopologyStart, '## AIOS Navigation', ''];

        if ($folder !== '') {
            $lines[] = '- Parent index: '.$this->link(Str::contains($folder, '/') ? Str::beforeLast($folder, '/').'/index.md' : 'index.md');
        }

        $lines[] = '- Folder metadata: '.$this->link($agents);
        foreach ($children as $child) {
            $lines[] = '- Note: '.$this->link($child);
        }
        foreach ($childFolders as $child) {
            $lines[] = '- Folder: '.$this->link($child.'/index.md');
        }
        $lines[] = self::TopologyEnd;

        return implode("\n", $lines)."\n";
    }

    private function agentsSection(string $folder, string $index): string
    {
        $lines = [self::TopologyStart, '## AIOS Folder Metadata', '', 'This file is navigation metadata only. It is not a repository AGENTS.md authority file and contains no executable instructions.', '', '- Purpose: organize '.($folder === '' ? 'the project knowledge root.' : '`'.$folder.'`.').'', '- Folder index: '.$this->link($index)];

        if ($folder !== '') {
            $lines[] = '- Parent index: '.$this->link(Str::contains($folder, '/') ? Str::beforeLast($folder, '/').'/index.md' : 'index.md');
        }

        $lines[] = '- Allowed contents: project-scoped Markdown notes and managed child folders only.';
        $lines[] = '- Navigation: AIOS maintains generated navigation sections.';
        $lines[] = self::TopologyEnd;

        return implode("\n", $lines)."\n";
    }

    private function navigationSection(string $index): string
    {
        return implode("\n", [self::NavigationStart, '## AIOS Navigation', '', '- Parent index: '.$this->link($index), self::NavigationEnd])."\n";
    }

    /** @param list<string> $sources */
    private function backlinksSection(array $sources): string
    {
        sort($sources, SORT_STRING);
        $lines = [self::BacklinksStart, '## AIOS Reciprocal Backlinks', ''];
        foreach ($sources as $source) {
            $lines[] = '- Linked from: '.$this->link($source);
        }
        if ($sources === []) {
            $lines[] = '- None.';
        }
        $lines[] = self::BacklinksEnd;

        return implode("\n", $lines)."\n";
    }

    private function replaceSection(string $content, string $start, string $end, string $replacement): string
    {
        $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'(?:\r?\n)?/s';

        if (preg_match($pattern, $content) === 1) {
            return preg_replace($pattern, rtrim($replacement)."\n", $content, 1) ?? $content;
        }

        return rtrim($content).($content === '' ? '' : "\n\n").rtrim($replacement)."\n";
    }

    /**
     * @param  list<string>  $sections
     * @param  array{created: list<string>, changed: list<array{path: string, sections: list<string>}>, unchanged: list<string>}  $changes
     */
    private function recordWrite(string $root, string $reference, string $path, string $before, string $after, array $sections, array &$changes): void
    {
        if ($before === $after) {
            $changes['unchanged'][] = $reference;

            return;
        }
        if (! $this->isSafeWritePath($root, $path)) {
            return;
        }
        $this->files->put($path, $after);

        if ($before === '') {
            $changes['created'][] = $reference;

            return;
        }
        $changes['changed'][] = ['path' => $reference, 'sections' => $sections];
    }

    /** @return array<string, string> */
    private function markdownNotes(string $root): array
    {
        $notes = [];

        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || Str::lower($file->getExtension()) !== 'md') {
                    continue;
                }
                $path = $file->getRealPath();
                $reference = $this->relativeReference($root, $path);
                if ($reference !== null) {
                    $notes[$reference] = $path;
                }
            }
        } catch (Throwable) {
            return [];
        }

        ksort($notes, SORT_STRING);

        return $notes;
    }

    /**
     * @param  array<string, string>  $notes
     * @return list<string>
     */
    private function foldersFor(array $notes): array
    {
        $folders = [''];
        foreach (array_keys($notes) as $reference) {
            for ($folder = dirname($reference); $folder !== '.' && $folder !== DIRECTORY_SEPARATOR; $folder = dirname($folder)) {
                $folders[] = str_replace(DIRECTORY_SEPARATOR, '/', $folder);
            }
        }
        $folders = array_values(array_unique($folders));
        usort($folders, fn (string $left, string $right): int => [substr_count($left, '/'), $left] <=> [substr_count($right, '/'), $right]);

        return $folders;
    }

    /**
     * @param  array<string, string>  $notes
     * @return array<string, list<string>>
     */
    private function explicitBacklinks(array $notes): array
    {
        $backlinks = [];
        foreach ($notes as $source => $path) {
            if ($this->isTopologyFile($source)) {
                continue;
            }
            preg_match_all('/\[\[([^\]|#]+)(?:#[^\]|]*)?(?:\|[^\]]*)?\]\]/', $this->withoutManagedSections($this->files->get($path)), $matches);
            foreach ($matches[1] as $target) {
                $target = $this->normalizeReference((string) $target);
                if ($target !== null && isset($notes[$target]) && $target !== $source) {
                    $backlinks[$target][] = $source;
                }
            }
        }
        foreach ($backlinks as $target => $sources) {
            $backlinks[$target] = array_values(array_unique($sources));
        }

        return $backlinks;
    }

    private function withoutManagedSections(string $content): string
    {
        foreach ([[self::TopologyStart, self::TopologyEnd], [self::NavigationStart, self::NavigationEnd], [self::BacklinksStart, self::BacklinksEnd]] as [$start, $end]) {
            $content = preg_replace('/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s', '', $content) ?? $content;
        }

        return $content;
    }

    private function normalizeReference(string $reference): ?string
    {
        $reference = str_replace('\\', '/', trim($reference));
        if ($reference === '' || Str::contains($reference, "\0") || Str::startsWith($reference, '/') || preg_match('/^[A-Za-z]:\//', $reference) === 1) {
            return null;
        }
        $parts = explode('/', $reference);
        if (array_filter($parts, fn (string $part): bool => $part === '' || $part === '.' || $part === '..' || Str::startsWith($part, '.')) !== []) {
            return null;
        }
        $reference = implode('/', $parts);

        return Str::endsWith(Str::lower($reference), '.md') ? $reference : $reference.'.md';
    }

    private function projectDirectory(Project $project, bool $create): ?string
    {
        $vault = config('aios.obsidian_vault_path');
        if (! is_string($vault) || $vault === '' || ($realVault = realpath($vault)) === false || ! is_dir($realVault)) {
            return null;
        }
        $projects = $realVault.DIRECTORY_SEPARATOR.'Projects';
        if ($create) {
            $this->files->ensureDirectoryExists($projects);
        }
        $realProjects = realpath($projects);
        if ($realProjects === false || ! Str::startsWith($realProjects, rtrim($realVault, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }
        $directory = $realProjects.DIRECTORY_SEPARATOR.Str::slug($project->name);
        if ($create) {
            $this->files->ensureDirectoryExists($directory);
        }
        $realDirectory = realpath($directory);

        return $realDirectory !== false && Str::startsWith($realDirectory, rtrim($realProjects, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) ? $realDirectory : null;
    }

    private function relativeReference(string $root, string|false $path): ?string
    {
        if (! is_string($path) || ! Str::startsWith($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $this->normalizeReference(Str::after($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR));
    }

    private function isSafeWritePath(string $root, string $path): bool
    {
        $parent = realpath(dirname($path));

        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $parent !== false
            && ($parent === $root || Str::startsWith($parent, $root.DIRECTORY_SEPARATOR));
    }

    private function isTopologyFile(string $reference): bool
    {
        return in_array(basename($reference), ['index.md', 'AGENTS.md'], true);
    }

    private function link(string $reference): string
    {
        return '[['.$reference.']]';
    }
}
