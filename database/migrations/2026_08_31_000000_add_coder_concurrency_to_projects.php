<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the authorized bounded per-project Coder concurrency setting, defaulting every project to serial.
     * The 1-or-2 bound is enforced by the authorized update Action/Request, matching this codebase's
     * existing convention of validating bounded scalar columns at the application layer.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedTinyInteger('coder_concurrency')->default(1)->after('stewardship_policy');
        });
    }

    /**
     * Remove the bounded Coder concurrency setting.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('coder_concurrency');
        });
    }
};
