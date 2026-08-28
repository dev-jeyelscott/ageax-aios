<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add durable worker slot identity while preserving every existing worker as slot 1.
     */
    public function up(): void
    {
        Schema::table('agent_workers', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'role']);
            $table->unsignedInteger('slot')->default(1)->after('role');
            $table->unique(['project_id', 'role', 'slot']);
        });
    }

    /**
     * Restore the legacy one-worker-per-role schema only when no additional slots would be lost.
     */
    public function down(): void
    {
        if (
            DB::table('agent_workers')
                ->where('slot', '<>', 1)
                ->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back worker slots while non-primary worker slots exist.',
            );
        }

        Schema::table('agent_workers', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'role', 'slot']);
            $table->dropColumn('slot');
            $table->unique(['project_id', 'role']);
        });
    }
};
