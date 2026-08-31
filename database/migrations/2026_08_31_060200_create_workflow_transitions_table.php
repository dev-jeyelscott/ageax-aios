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
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_step_id')->constrained('workflow_steps')->cascadeOnDelete();
            $table->foreignId('to_step_id')->constrained('workflow_steps')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'from_step_id', 'to_step_id']);

            // Composite foreign keys guarantee, at the database level, that both endpoints of a
            // transition belong to the exact same workflow definition version being referenced.
            $table->foreign(['workflow_definition_id', 'from_step_id'])
                ->references(['workflow_definition_id', 'id'])
                ->on('workflow_steps')
                ->cascadeOnDelete();
            $table->foreign(['workflow_definition_id', 'to_step_id'])
                ->references(['workflow_definition_id', 'id'])
                ->on('workflow_steps')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};
