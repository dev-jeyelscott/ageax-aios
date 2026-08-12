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
        Schema::create('vault_organization_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->index();
            $table->string('prompt_hash', 64);
            $table->json('report')->nullable();
            $table->unsignedBigInteger('token_usage')->nullable();
            $table->string('log_path')->nullable();
            $table->longText('live_output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vault_organization_runs');
    }
};
