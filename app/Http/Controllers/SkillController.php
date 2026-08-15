<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSkillRequest;
use App\Http\Requests\UpdateSkillRequest;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class SkillController extends Controller
{
    public function store(
        StoreSkillRequest $request,
        Project $project,
    ): RedirectResponse {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug($project, $validated['name']);

        $project->skills()->create($validated);

        return to_route('projects.show', $project);
    }

    public function update(
        UpdateSkillRequest $request,
        Project $project,
        Skill $skill,
    ): RedirectResponse {
        abort_unless($skill->project_id === $project->id, 404);

        $skill->update($request->validated());

        return to_route('projects.show', $project);
    }

    public function destroy(
        Project $project,
        Skill $skill,
    ): RedirectResponse {
        abort_unless($skill->project_id === $project->id, 404);

        $skill->delete();

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
