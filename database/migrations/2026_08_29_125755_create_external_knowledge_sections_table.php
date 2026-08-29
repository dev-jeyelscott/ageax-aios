<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the deterministic local full-text index of approved external knowledge sections.
     */
    public function up(): void
    {
        Schema::create('external_knowledge_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_source_manifest_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('source_reference', 500);
            $table->string('scope', 16);
            $table->unsignedBigInteger('scoped_agent_id')->nullable();
            $table->string('heading', 500);
            $table->unsignedTinyInteger('heading_level');
            $table->unsignedInteger('position');
            $table->text('content');
            $table->text('search_text');
            $table->unsignedInteger('character_count');
            $table->char('content_hash', 64);
            $table->timestamp('indexed_at');
            $table->timestamps();

            $table->unique(
                ['knowledge_source_manifest_id', 'position'],
                'external_knowledge_sections_version_position_unique',
            );

            $table->index(
                ['project_id', 'scope', 'scoped_agent_id'],
                'external_knowledge_sections_scope_index',
            );

            $table->index(
                ['project_id', 'source_reference', 'position'],
                'external_knowledge_sections_order_index',
            );
        });
    }

    /**
     * Remove the external knowledge section index.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_knowledge_sections');
    }
};
