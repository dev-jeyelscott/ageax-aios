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
            $table->timestamp('task_completed_at')->nullable()->after('stopped_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_workers', function (Blueprint $table) {
            $table->dropColumn('task_completed_at');
        });
    }
};
