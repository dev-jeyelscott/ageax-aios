<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_triage_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number');
            $table->string('status')->index();
            $table->json('structured_decision')->nullable();
            $table->timestamp('claimed_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_triage_attempts');
    }
};
