<?php

namespace App\Models;

use App\AgentHarness;
use App\AgentRole;
use App\Concerns\RejectsSecretMaterial;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use LogicException;

#[Fillable(['project_id', 'name', 'role', 'harness', 'model', 'reasoning_setting', 'default_context', 'enabled'])]
/**
 * @property AgentRole $role
 * @property AgentHarness $harness
 * @property bool $enabled
 * @property int $configuration_version
 */
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    use RejectsSecretMaterial;

    private const array VERSIONED_ATTRIBUTES = [
        'name',
        'role',
        'harness',
        'model',
        'reasoning_setting',
        'default_context',
        'enabled',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
        'configuration_version' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (Agent $agent): void {
            $agent->configuration_version = 1;
            $agent->assertConfigurationIsValid();
        });

        static::updating(function (Agent $agent): void {
            $agent->assertConfigurationIsValid();

            if ($agent->isDirty('project_id')) {
                throw new LogicException('Agent project ownership cannot be changed.');
            }

            $currentVersion = $agent->latestPersistedConfigurationVersion();

            $agent->configuration_version = $agent->isDirty(self::VERSIONED_ATTRIBUTES)
                ? $currentVersion + 1
                : $currentVersion;
        });
    }

    protected function casts(): array
    {
        return [
            'role' => AgentRole::class,
            'harness' => AgentHarness::class,
            'enabled' => 'boolean',
            'configuration_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<Skill, $this, AgentSkill> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)
            ->using(AgentSkill::class)
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /** @return Collection<int, Skill> */
    public function effectiveSkills(): Collection
    {
        return $this->skills()->where('skills.enabled', true)->get();
    }

    /**
     * @return int<1, max>
     */
    private function latestPersistedConfigurationVersion(): int
    {
        $version = (int) static::query()
            ->whereKey($this->getKey())
            ->value('configuration_version');

        return $version < 1 ? 1 : $version;
    }

    private function assertConfigurationIsValid(): void
    {
        $role = $this->getAttribute('role');

        if (! $role instanceof AgentRole || ! in_array($role, [AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer], true)) {
            throw new LogicException('Agent role must be a supported AIOS workflow role.');
        }

        if (! ($this->getAttribute('harness') instanceof AgentHarness)) {
            throw new LogicException('Agent harness must be supported by AIOS.');
        }

        foreach (['name', 'model', 'reasoning_setting', 'default_context'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (is_string($value) && $this->containsSecretMaterial($value)) {
                throw new LogicException('Agent configuration cannot contain secret material.');
            }
        }
    }
}
