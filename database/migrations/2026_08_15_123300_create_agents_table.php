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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role');
            $table->string('harness');
            $table->string('model')->nullable();
            $table->string('reasoning_setting')->nullable();
            $table->text('default_context')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('configuration_version')->default(1);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
