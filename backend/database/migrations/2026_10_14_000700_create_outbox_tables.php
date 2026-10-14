<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 80);
            $table->char('request_hash', 64)->nullable();
            $table->enum('kind', ['draft', 'send']);
            $table->enum('status', ['draft', 'queued', 'sending', 'sent', 'failed', 'unknown']);
            $table->string('message_id_header', 255)->nullable();
            $table->binary('payload_enc')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error_code', 40)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->ulid('in_reply_to_metadata_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('outbox_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outbox_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('storage_path', 255);
            $table->string('filename', 200);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->enum('scan_status', ['pending', 'clean', 'infected', 'not_scanned'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_attachments');
        Schema::dropIfExists('outbox_messages');
    }
};
