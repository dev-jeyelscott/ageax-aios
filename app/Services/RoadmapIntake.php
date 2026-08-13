<?php

namespace App\Services;

use App\Jobs\ProcessRoadmap;
use App\Models\Project;
use App\Models\Roadmap;
use App\ProjectStatus;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RoadmapIntake
{
    public function __construct(private Filesystem $files, private AuditLogger $audit, private ObsidianProjectNotes $notes) {}

    public function captureUpload(Project $project, UploadedFile $file): ?Roadmap
    {
        $path = $file->getRealPath();
        $content = $path === false ? false : file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('The roadmap file could not be read.');
        }

        return $this->capture($project, $file->getClientOriginalName(), $content, 'upload', 'upload://'.$file->getClientOriginalName());
    }

    public function scan(Project $project): ?Roadmap
    {
        if (ProjectStatus::from($project->getRawOriginal('status')) !== ProjectStatus::Running || ! $this->scanIsDue($project)) {
            return null;
        }

        $sourcePath = $this->sourcePath($project);
        if ($sourcePath === null || ! $this->files->isFile($sourcePath) || ! $this->files->isReadable($sourcePath)) {
            $project->update(['roadmap_scanned_at' => now()]);

            return null;
        }

        $roadmap = $this->capture($project, 'Implementation Roadmap.md', $this->files->get($sourcePath), 'vault', $sourcePath);
        $project->update(['roadmap_scanned_at' => now()]);

        return $roadmap;
    }

    private function capture(Project $project, string $filename, string $content, string $source, string $sourcePath): ?Roadmap
    {
        $hash = hash('sha256', $content);
        $roadmap = DB::transaction(function () use ($project, $filename, $content, $source, $sourcePath, $hash): ?Roadmap {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = $lockedProject->roadmaps()->where('content_hash', $hash)->first();
            if ($existing !== null) {
                $this->audit->record('roadmap.intake_skipped', ['source' => $source, 'content_hash' => $hash, 'roadmap_id' => $existing->id], $lockedProject);

                return null;
            }

            $storagePath = 'roadmaps/'.$lockedProject->id.'/'.Str::uuid().'.md';
            if (! Storage::disk('local')->put($storagePath, $content)) {
                throw new RuntimeException('The roadmap could not be persisted.');
            }

            return Roadmap::create([
                'project_id' => $lockedProject->id,
                'original_filename' => $filename,
                'storage_path' => $storagePath,
                'content' => $content,
                'content_hash' => $hash,
                'source' => $source,
                'source_path' => $sourcePath,
            ]);
        }, attempts: 3);

        if ($roadmap === null) {
            return null;
        }

        $this->archiveInVault($roadmap);
        $this->notes->writeRoadmapUpload($roadmap);
        $this->audit->record('roadmap.intake_captured', ['source' => $source, 'content_hash' => $hash, 'source_path' => $sourcePath, 'roadmap_id' => $roadmap->id], $project);
        ProcessRoadmap::dispatch($roadmap->id);

        return $roadmap;
    }

    private function scanIsDue(Project $project): bool
    {
        $lastScan = $project->getRawOriginal('roadmap_scanned_at');

        return $lastScan === null
            || CarbonImmutable::parse($lastScan)->lte(now()->subHours(max(1, (int) config('aios.roadmap_scan_interval_hours'))));
    }

    private function sourcePath(Project $project): ?string
    {
        $vault = config('aios.obsidian_vault_path');

        if (! is_string($vault) || $vault === '') {
            return null;
        }

        return $vault.'/Projects/'.Str::slug($project->name).'/Roadmaps/Implementation Roadmap.md';
    }

    private function archiveInVault(Roadmap $roadmap): void
    {
        $vault = config('aios.obsidian_vault_path');
        if (! is_string($vault) || $vault === '') {
            return;
        }

        $date = now()->format('Ymd');
        $this->files->ensureDirectoryExists($vault.'/raw/sources');
        $sourceId = 'SRC-'.$date.'-'.str_pad((string) (count($this->files->files($vault.'/raw/sources')) + 1), 3, '0', STR_PAD_LEFT);
        $filename = $sourceId.'-'.Str::slug(pathinfo($roadmap->original_filename, PATHINFO_FILENAME)).'.md';
        $inboxPath = $vault.'/inbox/'.$filename;
        $rawPath = $vault.'/raw/sources/'.$filename;
        $this->files->ensureDirectoryExists(dirname($inboxPath));
        $this->files->ensureDirectoryExists(dirname($rawPath));
        $this->files->put($inboxPath, $roadmap->content);
        $this->files->put($rawPath, $roadmap->content);
        $this->files->append($vault.'/index.md', "\n- [{$sourceId} — {$roadmap->original_filename}](raw/sources/{$filename}) — AIOS roadmap for {$roadmap->project->name}.\n");
        $this->files->append($vault.'/log.md', "\n## [".now()->toDateString()."] ingest | {$roadmap->project->name} roadmap\n\n- Preserved {$sourceId} from {$roadmap->source}.\n");
    }
}
