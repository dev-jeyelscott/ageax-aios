<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'name']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->unique(['project_id', 'name']);
        });

        // A global Agent (project_id IS NULL) is a singleton per AIOS system role: at most one
        // global Agent may exist for a given role. Project agents are unaffected: NULL project_id
        // values are the only rows this partial index covers.
        DB::statement('create unique index agents_global_role_unique on agents (role) where project_id is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index if exists agents_global_role_unique');

        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'name']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->unique(['project_id', 'name']);
        });
    }
};
