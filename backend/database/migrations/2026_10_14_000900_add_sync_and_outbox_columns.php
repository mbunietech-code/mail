<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (expand-only): columns needed by the sync engine, threading and the outbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_metadata', function (Blueprint $table) {
            $table->string('provider_thread_id', 64)->nullable()->after('provider_message_id');
            $table->json('references_json')->nullable()->after('references_hash');
            $table->json('reply_to_json')->nullable()->after('cc_json');
            $table->index(['email_account_id', 'provider_thread_id'], 'mm_account_provider_thread_index');
            $table->index(['email_account_id', 'in_reply_to'], 'mm_account_in_reply_to_index');
        });

        Schema::table('sync_states', function (Blueprint $table) {
            $table->string('history_id', 32)->nullable()->after('highest_modseq');
        });

        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')->nullable()->after('claimed_at');
            $table->unsignedSmallInteger('recipient_count')->default(0)->after('payload_enc');
            $table->ulid('forward_of_metadata_id')->nullable()->after('in_reply_to_metadata_id');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropColumn(['next_attempt_at', 'recipient_count', 'forward_of_metadata_id']);
        });

        Schema::table('sync_states', function (Blueprint $table) {
            $table->dropColumn('history_id');
        });

        Schema::table('message_metadata', function (Blueprint $table) {
            $table->dropIndex('mm_account_provider_thread_index');
            $table->dropIndex('mm_account_in_reply_to_index');
            $table->dropColumn(['provider_thread_id', 'references_json', 'reply_to_json']);
        });
    }
};
