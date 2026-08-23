<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add backward-compatible runtime failure identity and occurrence metadata.
     */
    public function up(): void
    {
        Schema::table('recovery_incidents', function (Blueprint $table) {
            $table->string('fingerprint', 64)->nullable()->after('failure_type');
            $table->string('source')->nullable()->after('fingerprint');
            $table->string('exception_class')->nullable()->after('source');
            $table->unsignedInteger('occurrence_count')->default(1)->after('exception_class');
            $table->timestamp('first_seen_at')->nullable()->after('occurrence_count');
            $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');

            $table->index(
                ['fingerprint', 'project_id', 'task_id', 'status'],
                'recovery_incidents_runtime_lookup_idx',
            );
        });
    }

    /**
     * Remove only the P7-001 runtime failure metadata and lookup index.
     */
    public function down(): void
    {
        Schema::table('recovery_incidents', function (Blueprint $table) {
            $table->dropIndex('recovery_incidents_runtime_lookup_idx');
            $table->dropColumn([
                'fingerprint',
                'source',
                'exception_class',
                'occurrence_count',
                'first_seen_at',
                'last_seen_at',
            ]);
        });
    }
};
