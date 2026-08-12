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
        Schema::create('review_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('severity');
            $table->string('location')->nullable();
            $table->text('current_implementation');
            $table->text('expected_implementation');
            $table->text('why_incorrect');
            $table->text('required_fix');
            $table->text('verification_requirement');
            $table->text('implementation_fix_context');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_findings');
    }
};
