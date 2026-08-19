<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phases', function (Blueprint $table): void {
            $table->string('system_key', 100)
                ->nullable()
                ->after('objective');
            $table->unique(['project_id', 'system_key']);
        });
    }

    public function down(): void
    {
        Schema::table('phases', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
