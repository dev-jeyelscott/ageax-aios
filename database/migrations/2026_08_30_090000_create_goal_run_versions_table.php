<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_run_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goal_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('goal_text');
            $table->string('source');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['goal_run_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_run_versions');
    }
};
