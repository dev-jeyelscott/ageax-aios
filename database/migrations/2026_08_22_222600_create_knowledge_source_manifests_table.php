<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create temporal metadata-only knowledge source evidence.
     */
    public function up(): void
    {
        Schema::create('knowledge_source_manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 64);
            $table->string('source_reference', 500);
            $table->char('content_hash', 64);
            $table->string('git_sha', 64)->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('last_verified_at');
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamps();

            $table->foreign('superseded_by_id')
                ->references('id')
                ->on('knowledge_source_manifests')
                ->nullOnDelete();

            $table->index(
                ['project_id', 'source_type', 'source_reference'],
                'knowledge_source_manifests_identity_index',
            );

            $table->index(
                ['project_id', 'last_verified_at'],
                'knowledge_source_manifests_verified_index',
            );

            $table->index('superseded_at');
        });

        DB::statement(
            'CREATE UNIQUE INDEX knowledge_source_manifests_current_unique
             ON knowledge_source_manifests (project_id, source_type, source_reference)
             WHERE superseded_at IS NULL',
        );
    }

    /**
     * Remove the knowledge source manifest history.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_source_manifests');
    }
};
