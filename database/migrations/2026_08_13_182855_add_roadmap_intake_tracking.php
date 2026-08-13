<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('content');
            $table->string('source')->default('upload')->after('content_hash');
            $table->string('source_path')->nullable()->after('source');
            $table->unique(['project_id', 'content_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'content_hash']);
            $table->dropColumn(['content_hash', 'source', 'source_path']);
        });
    }
};
