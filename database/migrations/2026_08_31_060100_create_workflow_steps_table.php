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
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->unsignedInteger('position');
            $table->string('kind');
            $table->string('label');
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'key']);
            $table->unique(['workflow_definition_id', 'position']);
            // Required so workflow_transitions can enforce, at the database level, that a
            // referenced step belongs to the exact same workflow definition version.
            $table->unique(['workflow_definition_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
