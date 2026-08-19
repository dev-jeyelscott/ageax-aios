<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_escalation_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_triage_attempt_id')
                ->constrained('ticket_triage_attempts')
                ->cascadeOnDelete();
            $table->foreignId('decided_by_user_id')->constrained('users');
            $table->string('action', 64);
            $table->text('direction')->nullable();
            $table->timestamps();

            $table->unique('ticket_triage_attempt_id');
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_escalation_decisions');
    }
};
