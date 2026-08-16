<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the singleton global Recovery Engineer Agent from the values it has always run with
     * (config('aios.recovery_engineer_*')), so this migration preserves current behavior exactly
     * until an operator edits it through the new global Agent configuration UI.
     */
    public function up(): void
    {
        $exists = DB::table('agents')->whereNull('project_id')->where('role', 'recovery_engineer')->exists();

        if ($exists) {
            return;
        }

        $timestamp = now();

        DB::table('agents')->insert([
            'project_id' => null,
            'name' => 'AIOS Workflow Recovery Engineer',
            'role' => 'recovery_engineer',
            'harness' => (string) config('aios.recovery_engineer_harness'),
            'model' => config('aios.recovery_engineer_model'),
            'reasoning_setting' => config('aios.recovery_engineer_reasoning_setting'),
            'default_context' => null,
            'enabled' => true,
            'configuration_version' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * Historical Agent configuration is intentionally preserved on rollback.
     */
    public function down(): void {}
};
