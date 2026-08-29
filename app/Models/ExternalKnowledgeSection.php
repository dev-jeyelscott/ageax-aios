<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'knowledge_source_manifest_id',
    'source_reference',
    'scope',
    'scoped_agent_id',
    'heading',
    'heading_level',
    'position',
    'content',
    'search_text',
    'character_count',
    'content_hash',
    'indexed_at',
])]
/**
 * One indexed heading-level section of approved external Markdown knowledge.
 *
 * @property CarbonImmutable $indexed_at
 */
class ExternalKnowledgeSection extends Model
{
    /**
     * Cast index metadata deterministically.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'indexed_at' => 'immutable_datetime',
            'heading_level' => 'integer',
            'position' => 'integer',
            'character_count' => 'integer',
            'scoped_agent_id' => 'integer',
        ];
    }

    /**
     * Return the project whose retrieval identity this indexed section belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the observed source version this section was indexed from.
     *
     * @return BelongsTo<KnowledgeSourceManifest, $this>
     */
    public function knowledgeSourceManifest(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSourceManifest::class);
    }
}
