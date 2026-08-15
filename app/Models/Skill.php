<?php

namespace App\Models;

use App\AgentRole;
use App\Concerns\RejectsSecretMaterial;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

#[Fillable(['project_id', 'name', 'slug', 'description', 'instructions', 'constraints', 'applicable_roles', 'enabled'])]
/**
 * @property list<string> $applicable_roles
 * @property bool $enabled
 * @property int $version
 */
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    use RejectsSecretMaterial;

    private const array VERSIONED_ATTRIBUTES = [
        'name',
        'slug',
        'description',
        'instructions',
        'constraints',
        'applicable_roles',
        'enabled',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
        'version' => 1,
        'applicable_roles' => '[]',
    ];

    protected static function booted(): void
    {
        static::creating(function (Skill $skill): void {
            $skill->version = 1;
            $skill->assertConfigurationIsValid();
        });

        static::updating(function (Skill $skill): void {
            $skill->assertConfigurationIsValid();

            if ($skill->isDirty('project_id')) {
                throw new LogicException('Skill project ownership cannot be changed.');
            }

            $currentVersion = max(1, (int) $skill->getOriginal('version'));
            $skill->version = $skill->isDirty(self::VERSIONED_ATTRIBUTES)
                ? $currentVersion + 1
                : $currentVersion;
        });
    }

    protected function casts(): array
    {
        return [
            'applicable_roles' => 'array',
            'enabled' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<Agent, $this, AgentSkill> */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class)
            ->using(AgentSkill::class)
            ->withPivot('position')
            ->withTimestamps();
    }

    /** @param Builder<Skill> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    private function assertConfigurationIsValid(): void
    {
        $instructions = $this->getAttribute('instructions');
        if (! is_string($instructions) || trim($instructions) === '') {
            throw new LogicException('Skill instructions are required.');
        }

        $roles = $this->getAttribute('applicable_roles');
        if (! is_array($roles)) {
            throw new LogicException('Skill applicable roles must be an array.');
        }

        $validRoles = array_map(static fn (AgentRole $role): string => $role->value, AgentRole::cases());
        foreach ($roles as $role) {
            if (! is_string($role) || ! in_array($role, $validRoles, true)) {
                throw new LogicException('Skill applicable roles must reference supported AIOS workflow roles.');
            }
        }

        foreach (['name', 'description', 'instructions', 'constraints'] as $attribute) {
            $value = $this->getAttribute($attribute);
            if (is_string($value) && $this->containsSecretMaterial($value)) {
                throw new LogicException('Skill configuration cannot contain secret material.');
            }
        }
    }
}
