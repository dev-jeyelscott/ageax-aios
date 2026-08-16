<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSkillRequest;
use App\Http\Requests\UpdateSkillRequest;
use App\Models\Project;
use App\Models\Skill;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SkillController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(
        StoreSkillRequest $request,
        Project $project,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $project): void {
            $validated = $request->validated();
            $validated['slug'] = $this->uniqueSlug($project, $validated['name']);

            $skill = $project->skills()->create($validated);

            $this->audit->record('skill.created', [
                'project_id' => $project->id,
                'skill_id' => $skill->id,
                'version' => $skill->version,
                'slug' => $skill->slug,
            ], $project);
        }, attempts: 3);

        return to_route('projects.show', $project);
    }

    public function update(
        UpdateSkillRequest $request,
        Project $project,
        Skill $skill,
    ): RedirectResponse {
        abort_unless($skill->project_id === $project->id, 404);

        DB::transaction(function () use ($request, $project, $skill): void {
            $previousVersion = $skill->version;
            $wasEnabled = $skill->enabled;

            $skill->update($request->validated());
            $skill->refresh();

            if ($skill->version === $previousVersion) {
                return;
            }

            $payload = [
                'project_id' => $project->id,
                'skill_id' => $skill->id,
                'previous_version' => $previousVersion,
                'version' => $skill->version,
                'slug' => $skill->slug,
            ];

            $this->audit->record('skill.updated', $payload, $project);

            if ($wasEnabled !== $skill->enabled) {
                $this->audit->record(
                    $skill->enabled ? 'skill.enabled' : 'skill.disabled',
                    $payload,
                    $project,
                );
            }
        }, attempts: 3);

        return to_route('projects.show', $project);
    }

    public function destroy(
        Project $project,
        Skill $skill,
    ): RedirectResponse {
        abort_unless($skill->project_id === $project->id, 404);

        DB::transaction(function () use ($project, $skill): void {
            $this->audit->record('skill.deleted', [
                'project_id' => $project->id,
                'skill_id' => $skill->id,
                'version' => $skill->version,
                'slug' => $skill->slug,
            ], $project);

            $skill->delete();
        }, attempts: 3);

        return to_route('projects.show', $project);
    }

    private function uniqueSlug(Project $project, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while ($project->skills()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
