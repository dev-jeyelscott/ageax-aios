<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_reconciliation_runs', function (Blueprint $table): void {
            $table->json('mechanical_result')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('project_reconciliation_runs', function (Blueprint $table): void {
            $table->dropColumn('mechanical_result');
        });
    }
};
