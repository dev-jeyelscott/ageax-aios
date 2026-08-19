<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestamp('inactivity_closed_at')->nullable();
            $table->index(
                ['status', 'decision', 'awaiting_response_until'],
                'tickets_requester_deadline_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_requester_deadline_index');
            $table->dropColumn('inactivity_closed_at');
        });
    }
};
