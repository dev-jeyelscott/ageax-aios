<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->string('provider_definition_path')->nullable()->after('default_context');
            $table->string('provider_definition_hash', 64)->nullable()->after('provider_definition_path');
            $table->string('provider_definition_version')->nullable()->after('provider_definition_hash');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn(['provider_definition_path', 'provider_definition_hash', 'provider_definition_version']);
        });
    }
};
