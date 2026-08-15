<?php

use App\Models\Project;
use App\Models\Skill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

function createSkillPersistenceProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-skill-test-'.Str::uuid(),
    ]);
}

test('project skills persist as declarative context owned by a project', function () {
    $skill = Skill::factory()->create();

    expect($skill->project)->toBeInstanceOf(Project::class)
        ->and($skill->enabled)->toBeTrue()
        ->and($skill->version)->toBe(1)
        ->and($skill->project->skills()->whereKey($skill->id)->exists())->toBeTrue();
});

test('skill rows cannot exist without an owning project', function () {
    expect(fn () => Skill::query()->create([
        'name' => 'Global Skill',
        'slug' => 'global-skill',
        'instructions' => 'Do the thing.',
        'applicable_roles' => [],
        'enabled' => true,
    ]))->toThrow(QueryException::class);
});

test('skill name and slug are unique only within their owning project', function () {
    $firstProject = createSkillPersistenceProject('First skill project');
    $secondProject = createSkillPersistenceProject('Second skill project');

    Skill::factory()->for($firstProject)->create(['name' => 'Reviewer Checklist', 'slug' => 'reviewer-checklist']);
    Skill::factory()->for($secondProject)->create(['name' => 'Reviewer Checklist', 'slug' => 'reviewer-checklist']);

    expect(fn () => Skill::factory()->for($firstProject)->create(['name' => 'Reviewer Checklist', 'slug' => 'reviewer-checklist']))
        ->toThrow(QueryException::class);

    expect(fn () => Skill::factory()->for($firstProject)->create(['name' => 'Different Name', 'slug' => 'reviewer-checklist']))
        ->toThrow(QueryException::class);
});

test('skill instructions are required', function () {
    $project = createSkillPersistenceProject('Instruction-required skill project');

    expect(fn () => Skill::factory()->for($project)->create(['instructions' => '']))
        ->toThrow(LogicException::class, 'Skill instructions are required.');
});

test('skill applicable roles must reference supported workflow roles', function () {
    $project = createSkillPersistenceProject('Bounded skill roles');

    expect(fn () => Skill::factory()->for($project)->create(['applicable_roles' => ['not_a_real_role']]))
        ->toThrow(LogicException::class, 'Skill applicable roles must reference supported AIOS workflow roles.');

    $skill = Skill::factory()->for($project)->create(['applicable_roles' => ['coder', 'reviewer']]);

    expect($skill->applicable_roles)->toBe(['coder', 'reviewer']);
});

test('skill configuration changes increment version and callers cannot override it', function () {
    $project = createSkillPersistenceProject('Versioned skill configuration');
    $skill = Skill::factory()->for($project)->create(['version' => 99]);

    expect($skill->version)->toBe(1);

    $skill->update(['instructions' => 'Always validate task acceptance criteria first.']);

    expect($skill->refresh()->version)->toBe(2);

    $skill->version = 99;
    $skill->save();

    expect($skill->refresh()->version)->toBe(2);

    $skill->version = 500;
    $skill->update(['enabled' => false]);

    expect($skill->refresh()->version)->toBe(3)
        ->and($skill->enabled)->toBeFalse();
});

test('disabling a skill keeps the row persisted', function () {
    $project = createSkillPersistenceProject('Disabled skill persistence');
    $skill = Skill::factory()->for($project)->create();

    $skill->update(['enabled' => false]);

    expect(Skill::query()->whereKey($skill->id)->exists())->toBeTrue()
        ->and($skill->refresh()->enabled)->toBeFalse();
});

test('skill project ownership cannot be moved after persistence', function () {
    $project = createSkillPersistenceProject('Original skill project');
    $otherProject = createSkillPersistenceProject('Other skill project');
    $skill = Skill::factory()->for($project)->create();

    $skill->project()->associate($otherProject);

    expect(fn () => $skill->save())
        ->toThrow(LogicException::class, 'Skill project ownership cannot be changed.')
        ->and($skill->fresh()->project_id)->toBe($project->id);
});

test('skill configuration rejects high confidence secret material', function (string $secret) {
    $project = createSkillPersistenceProject('Secret-safe skill configuration');

    expect(fn () => Skill::factory()->for($project)->create(['instructions' => $secret]))
        ->toThrow(LogicException::class, 'Skill configuration cannot contain secret material.');
})->with([
    'provider token' => 'sk-'.str_repeat('a', 24),
    'bearer credential' => 'Authorization: Bearer provider-secret-token',
    'environment credential' => 'API_KEY=super-secret-value',
    'lowercase environment credential' => 'provider_token=super-secret-value',
    'private key' => "-----BEGIN PRIVATE KEY-----\nsecret-material",
]);

test('skill instructions may describe security rules without being treated as a secret', function () {
    $project = createSkillPersistenceProject('Security guidance skill configuration');

    $skill = Skill::factory()->for($project)->create([
        'instructions' => 'Never expose API keys, access tokens, credentials, or .env contents.',
    ]);

    expect($skill->exists)->toBeTrue();
});
