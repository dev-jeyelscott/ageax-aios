<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the Coder-specific durable worker and lease ownership evidence used while a Task is active.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table
                ->foreignId('coder_worker_id')
                ->nullable()
                ->constrained('agent_workers')
                ->nullOnDelete();

            $table->uuid('coder_worker_lease_id')->nullable();

            $table->index(
                ['coder_worker_id', 'coder_worker_lease_id'],
                'tasks_coder_worker_ownership_idx',
            );
        });
    }

    /**
     * Remove the Coder-specific ownership evidence.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_coder_worker_ownership_idx');
            $table->dropConstrainedForeignId('coder_worker_id');
            $table->dropColumn('coder_worker_lease_id');
        });
    }
};
