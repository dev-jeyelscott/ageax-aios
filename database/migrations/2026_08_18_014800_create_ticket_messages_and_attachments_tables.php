<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('agent_run_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('author_type', 16);
            $table->string('message_type', 32)
                ->index();
            $table->text('body');
            $table->boolean('ai_generated')
                ->default(false);
            $table->timestamps();

            $table->index([
                'ticket_id',
                'created_at',
            ]);
        });

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('ticket_message_id')
                ->nullable()
                ->constrained('ticket_messages')
                ->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('original_name');
            $table->string('storage_disk', 32);
            $table->string('storage_path');
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_hash', 64);
            $table->timestamps();

            $table->unique([
                'storage_disk',
                'storage_path',
            ]);

            $table->index([
                'ticket_id',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
    }
};
