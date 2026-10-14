<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_device_id', 64);
            $table->enum('platform', ['android', 'windows', 'other']);
            $table->string('name', 80)->nullable();
            $table->text('push_token')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_device_id']);
        });

        // One row per refresh token. Rotation inserts a new row in the same family;
        // presenting an already-rotated token revokes the whole family.
        Schema::create('refresh_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('family_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_prefix', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('idle_expires_at');
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email', 254)->index();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('recovery_codes');
        Schema::dropIfExists('refresh_sessions');
        Schema::dropIfExists('devices');
    }
};
