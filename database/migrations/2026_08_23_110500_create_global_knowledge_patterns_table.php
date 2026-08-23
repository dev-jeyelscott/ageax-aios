<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the immutable global reusable engineering-pattern library.
     */
    public function up(): void
    {
        Schema::create('global_knowledge_patterns', function (Blueprint $table): void {
            $table->id();

            $table->char('pattern_key', 64);
            $table->string('name', 160);
            $table->string('category', 64);
            $table->unsignedInteger('version');

            $table->json('applicable_roles');
            $table->text('validated_guidance');

            $table->unsignedBigInteger('source_project_id');
            $table->unsignedBigInteger('source_candidate_id');
            $table->char('source_evidence_hash', 64);
            $table->json('source_evidence');

            $table->unsignedBigInteger('approved_by_user_id');

            $table->boolean('enabled')->default(true);
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['source_candidate_id', 'source_evidence_hash'],
                'global_knowledge_patterns_source_unique',
            );

            $table->unique(
                ['pattern_key', 'version'],
                'global_knowledge_patterns_version_unique',
            );

            $table->index(
                ['pattern_key', 'enabled'],
                'global_knowledge_patterns_current_index',
            );

            $table->index('category');
            $table->index('source_project_id');
            $table->index('approved_by_user_id');
        });
    }

    /**
     * Remove the reusable global pattern library.
     */
    public function down(): void
    {
        Schema::dropIfExists('global_knowledge_patterns');
    }
};
