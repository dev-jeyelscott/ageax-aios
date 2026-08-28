<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add durable operator attribution for recommendation lifecycle decisions.
     */
    public function up(): void
    {
        Schema::table('orchestration_recommendations', function (Blueprint $table): void {
            $table->foreignId('status_changed_by_user_id')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('status_changed_at')
                ->nullable()
                ->after('status_changed_by_user_id');
        });
    }

    /**
     * Remove only the additive P5-005 recommendation lifecycle metadata.
     */
    public function down(): void
    {
        Schema::table('orchestration_recommendations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('status_changed_by_user_id');
            $table->dropColumn('status_changed_at');
        });
    }
};
