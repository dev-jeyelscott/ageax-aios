<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')->orderBy('id')->each(function (object $project): void {
            $exists = DB::table('agents')->where('project_id', $project->id)->where('role', 'backend_engineer')->exists();
            if (! $exists) {
                DB::table('agents')->insert(['project_id' => $project->id, 'name' => 'Backend Engineer', 'role' => 'backend_engineer', 'harness' => 'codex', 'enabled' => true, 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        DB::table('agents')->where('role', 'backend_engineer')->where('name', 'Backend Engineer')->delete();
    }
};
