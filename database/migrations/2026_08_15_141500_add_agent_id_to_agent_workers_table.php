<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const array DEFAULT_AGENT_NAMES = [
        'project_manager' => 'Project Manager',
        'coder' => 'Coder',
        'reviewer' => 'Reviewer',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('agent_workers', 'agent_id')) {
            Schema::table('agent_workers', function (Blueprint $table) {
                $table->foreignId('agent_id')->nullable()->after('role')->constrained('agents')->restrictOnDelete();
            });
        }

        $this->backfillCoreWorkerBindings();
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_workers', 'agent_id')) {
            Schema::table('agent_workers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('agent_id');
            });
        }
    }

    private function backfillCoreWorkerBindings(): void
    {
        DB::table('agent_workers')
            ->select(['id', 'project_id', 'role'])
            ->whereNull('agent_id')
            ->whereIn('role', array_keys(self::DEFAULT_AGENT_NAMES))
            ->orderBy('id')
            ->chunkById(100, function ($workers): void {
                foreach ($workers as $worker) {
                    $query = DB::table('agents')
                        ->where('project_id', $worker->project_id)
                        ->where('role', $worker->role)
                        ->where('enabled', true);

                    $agentId = (clone $query)
                        ->where('name', self::DEFAULT_AGENT_NAMES[$worker->role])
                        ->value('id');

                    $agentId ??= (clone $query)->orderBy('id')->value('id');

                    if ($agentId === null) {
                        throw new RecordsNotFoundException("No enabled {$worker->role} Agent exists for project {$worker->project_id}.");
                    }

                    DB::table('agent_workers')
                        ->where('id', $worker->id)
                        ->whereNull('agent_id')
                        ->update(['agent_id' => $agentId]);
                }
            });
    }
};
