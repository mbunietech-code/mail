<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. In production the application DB user is granted only
        // INSERT and SELECT on this table (see docs/SECURITY_HARDENING_CHECKLIST.md).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 6);
            $table->ulid('actor_user_id')->nullable();
            $table->enum('actor_type', ['user', 'admin', 'system']);
            $table->string('action', 80);
            $table->string('target_type', 40)->nullable();
            $table->string('target_id', 26)->nullable();
            $table->enum('result', ['success', 'failure', 'denied']);
            $table->binary('ip', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['target_type', 'target_id']);
        });

        // Single-row table locked FOR UPDATE to serialise hash-chain appends.
        Schema::create('audit_chain_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->char('last_hash', 64);
        });
        DB::table('audit_chain_head')->insert(['id' => 1, 'last_id' => 0, 'last_hash' => str_repeat('0', 64)]);

        Schema::create('break_glass_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->text('justification');
            $table->string('legal_reference', 160)->nullable();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['pending', 'approved', 'declined', 'expired', 'closed'])->default('pending');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_requests');
        Schema::dropIfExists('audit_chain_head');
        Schema::dropIfExists('audit_logs');
    }
};
