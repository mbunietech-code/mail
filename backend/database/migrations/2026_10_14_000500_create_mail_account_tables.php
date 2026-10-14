<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('label', 80);
            $table->enum('driver', ['imap_smtp', 'gmail']);
            $table->string('imap_host', 253)->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->enum('imap_encryption', ['ssl', 'starttls'])->nullable();
            $table->string('smtp_host', 253)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->enum('smtp_encryption', ['ssl', 'starttls'])->nullable();
            $table->boolean('saves_sent_automatically')->default(false);
            $table->unsignedTinyInteger('max_connections_per_mailbox')->default(2);
            $table->json('domains');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('email_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('provider_profile_id')->constrained()->restrictOnDelete();
            $table->string('email_address', 254);
            $table->string('display_name', 120)->nullable();
            $table->enum('status', ['pending', 'active', 'syncing', 'auth_failed', 'provider_unreachable', 'throttled', 'disabled'])->default('pending');
            $table->string('last_error_code', 40)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->unsignedTinyInteger('backoff_attempts')->default(0);
            $table->mediumText('signature_html')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('gmail_history_id', 32)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'email_address']);
            $table->index(['status', 'next_sync_at']);
        });

        // Envelope-encrypted secrets (see SDD §9). 1:1 with email_accounts.
        Schema::create('encrypted_credentials', function (Blueprint $table) {
            $table->foreignUlid('email_account_id')->primary()->constrained()->cascadeOnDelete();
            $table->enum('kind', ['password', 'oauth']);
            $table->binary('ciphertext');
            $table->binary('nonce', 24, true);
            $table->binary('wrapped_dek');
            $table->unsignedSmallInteger('kek_version');
            $table->timestamps();
        });

        Schema::create('oauth_states', function (Blueprint $table) {
            $table->char('state_hash', 64)->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->text('pkce_verifier_enc');
            $table->string('redirect_after', 40)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('name', 160)->nullable();
            $table->enum('source', ['manual', 'derived'])->default('derived');
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'email']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('folder_role', 20)->default('inbox');
            $table->boolean('enabled')->default(true);
            $table->time('quiet_from')->nullable();
            $table->time('quiet_to')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'email_account_id', 'folder_role'], 'notif_pref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('oauth_states');
        Schema::dropIfExists('encrypted_credentials');
        Schema::dropIfExists('email_accounts');
        Schema::dropIfExists('provider_profiles');
    }
};
