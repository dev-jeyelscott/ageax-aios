<?php

namespace App\Models;

use App\AgentHarness;
use App\AgentRole;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

            $currentVersion = max(1, (int) $agent->getOriginal('configuration_version'));
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

    private function containsSecretMaterial(string $value): bool
    {
        return preg_match('/-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----/s', $value) === 1
            || preg_match('/(?i)authorization\s*:\s*bearer\s+[^\s"\']+/', $value) === 1
            || preg_match('/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16})\b/', $value) === 1
            || preg_match('/(?im)^\s*((?=[a-z0-9_]*(?:token|secret|password|api_key|app_key|private_key|credential))[a-z][a-z0-9_]*)\s*=\s*\S.*$/', $value) === 1;
    }
}
