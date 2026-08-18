<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        File::ensureDirectoryExists(dirname((string) config('database.connections.aios_backup_ledger.database')));

        Schema::connection('aios_backup_ledger')->create('database_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('status'); // creating|completed|failed|verified|corrupted|restored
            $table->string('reason');
            $table->string('driver');
            $table->string('connection_name');
            $table->string('artifact_path')->nullable();
            // No foreign key: agent_run_id is a plain attribution reference into the primary
            // database, which this ledger must remain independently readable without.
            $table->unsignedBigInteger('agent_run_id')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256')->nullable();
            $table->boolean('integrity_verified')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->json('restore_evidence')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('aios_backup_ledger')->dropIfExists('database_backups');
    }
};
