<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('remote_name', 255);
            $table->string('delimiter', 4)->nullable();
            $table->enum('role', ['inbox', 'sent', 'drafts', 'archive', 'trash', 'spam', 'custom'])->default('custom');
            $table->boolean('selectable')->default(true);
            $table->unsignedInteger('unread_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->timestamps();
            $table->unique(['email_account_id', 'remote_name']);
            $table->index(['email_account_id', 'role']);
        });

        Schema::create('sync_states', function (Blueprint $table) {
            $table->foreignUlid('folder_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->unsignedBigInteger('highest_modseq')->nullable();
            $table->date('window_start')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_incremental_at')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });

        // Metadata only — bodies are fetched on demand (SDD ADR-002).
        Schema::create('message_metadata', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('email_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('folder_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('uidvalidity')->default(0);
            $table->unsignedBigInteger('uid')->default(0);
            $table->string('provider_message_id', 64)->nullable();
            $table->string('message_id_header', 255)->nullable();
            $table->ulid('thread_key');
            $table->string('in_reply_to', 255)->nullable();
            $table->char('references_hash', 64)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 254)->nullable();
            $table->json('to_json')->nullable();
            $table->json('cc_json')->nullable();
            $table->binary('subject_enc')->nullable();
            $table->binary('snippet_enc')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('internal_date');
            $table->unsignedInteger('size_bytes')->default(0);
            $table->boolean('is_seen')->default(false);
            $table->boolean('is_flagged')->default(false);
            $table->boolean('is_answered')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->json('auth_results')->nullable();
            $table->timestamps();
            $table->unique(['folder_id', 'uidvalidity', 'uid']);
            $table->unique(['email_account_id', 'provider_message_id']);
            $table->index(['email_account_id', 'thread_key']);
            $table->index(['folder_id', 'internal_date']);
            $table->index(['email_account_id', 'from_email']);
            $table->rawIndex('email_account_id, message_id_header(191)', 'mm_account_msgid_index');
        });

        Schema::create('attachment_metadata', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_metadata_id')->constrained('message_metadata')->cascadeOnDelete();
            $table->string('part_id', 32);
            $table->string('filename', 200);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('content_id', 255)->nullable();
            $table->boolean('is_inline')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_metadata');
        Schema::dropIfExists('message_metadata');
        Schema::dropIfExists('sync_states');
        Schema::dropIfExists('folders');
    }
};
