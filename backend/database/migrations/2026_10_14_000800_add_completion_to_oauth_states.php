<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth account linking completes in two steps (callback, then the initiating app
 * session redeems a one-time completion code) to defeat account-linking CSRF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_states', function (Blueprint $table) {
            $table->ulid('session_family_id')->nullable()->after('user_id');
            $table->char('completion_hash', 64)->nullable()->unique()->after('redirect_after');
            $table->longText('pending_payload_enc')->nullable()->after('completion_hash');
            $table->timestamp('completed_at')->nullable()->after('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_states', function (Blueprint $table) {
            $table->dropUnique(['completion_hash']);
            $table->dropColumn(['session_family_id', 'completion_hash', 'pending_payload_enc', 'completed_at']);
        });
    }
};
