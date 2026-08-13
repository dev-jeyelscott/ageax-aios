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
        Schema::table('agent_workers', function (Blueprint $table) {
            $table->uuid('worker_instance_id')->nullable()->after('status')->index();
            $table->uuid('lease_id')->nullable()->after('worker_instance_id')->index();
            $table->timestamp('lease_expires_at')->nullable()->after('last_heartbeat_at')->index();
            $table->unsignedInteger('process_id')->nullable()->after('lease_expires_at');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->uuid('worker_instance_id')->nullable()->after('agent_worker_id')->index();
            $table->uuid('worker_lease_id')->nullable()->after('worker_instance_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['worker_instance_id']);
            $table->dropIndex(['worker_lease_id']);
            $table->dropColumn(['worker_instance_id', 'worker_lease_id']);
        });

        Schema::table('agent_workers', function (Blueprint $table) {
            $table->dropIndex(['worker_instance_id']);
            $table->dropIndex(['lease_id']);
            $table->dropIndex(['lease_expires_at']);
            $table->dropColumn(['worker_instance_id', 'lease_id', 'lease_expires_at', 'process_id']);
        });
    }
};
