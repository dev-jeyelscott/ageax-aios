<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateAttemptIds = DB::table('reviews')
            ->select('task_attempt_id')
            ->groupBy('task_attempt_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('task_attempt_id')
            ->pluck('task_attempt_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($duplicateAttemptIds !== []) {
            throw new RuntimeException(
                'Cannot enforce one Review per TaskAttempt because duplicate historical Review rows exist for task_attempt_id values: '
                .implode(', ', $duplicateAttemptIds)
                .'. Resolve the conflicting durable history explicitly before rerunning this migration; this migration will not delete or rewrite Review history.',
            );
        }

        Schema::table('reviews', function (Blueprint $table): void {
            $table->unique('task_attempt_id', 'reviews_task_attempt_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique('reviews_task_attempt_id_unique');
        });
    }
};
