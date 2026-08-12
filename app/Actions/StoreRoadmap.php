<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Roadmap;
use App\Services\AuditLogger;
use App\Services\ObsidianProjectNotes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StoreRoadmap
{
    public function __construct(private AuditLogger $audit, private ObsidianProjectNotes $notes) {}

    public function handle(Project $project, UploadedFile $file): Roadmap
    {
        $storagePath = $file->store('roadmaps/'.$project->id, 'local');
        if ($storagePath === false) {
            throw new RuntimeException('The roadmap file could not be stored.');
        }

        $roadmap = Roadmap::create([
            'project_id' => $project->id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'content' => Storage::disk('local')->get($storagePath),
        ]);
        $this->notes->writeRoadmapUpload($roadmap);
        $this->audit->record('roadmap.uploaded', ['filename' => $roadmap->original_filename], $project);

        return $roadmap;
    }
}
