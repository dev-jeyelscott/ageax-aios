<?php

namespace App\Actions;

use App\Models\FeatureSpec;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FeatureSpecStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreFeatureSpec
{
    public function __construct(private FeatureSpecStorage $storage, private AuditLogger $audit) {}

    public function handle(Project $project, UploadedFile $file, ?User $user = null): FeatureSpec
    {
        $stored = $this->storage->store($project, $file);

        return DB::transaction(function () use ($project, $user, $stored): FeatureSpec {
            if ($project->featureSpecs()->where('content_hash', $stored['content_hash'])->exists()) {
                throw ValidationException::withMessages(['feature' => 'This feature specification has already been uploaded for this project.']);
            }
            $featureSpec = FeatureSpec::create([...$stored, 'project_id' => $project->id, 'uploaded_by_user_id' => $user?->id, 'status' => 'uploaded']);
            $this->audit->record('feature_spec.stored', ['feature_spec_id' => $featureSpec->id, 'content_hash' => $featureSpec->content_hash, 'size_bytes' => $featureSpec->size_bytes], $project);

            return $featureSpec;
        }, attempts: 3);
    }
}
