<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention (SRS §10): an anchor lets the audit hash chain stay verifiable after old
 * rows are archived and pruned; legal hold blocks the deactivated-user purge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_chain_head', function (Blueprint $table) {
            $table->unsignedBigInteger('anchor_id')->default(1)->after('last_hash');
            $table->char('anchor_prev_hash', 64)->default(str_repeat('0', 64))->after('anchor_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('legal_hold_until')->nullable()->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('legal_hold_until'));
        Schema::table('audit_chain_head', fn (Blueprint $table) => $table->dropColumn(['anchor_id', 'anchor_prev_hash']));
    }
};
