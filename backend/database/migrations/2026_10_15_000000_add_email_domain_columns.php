<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexed, database-computed email domains so domain policy can be enforced and
 * reported in SQL (admin Domains page, sync scheduling, mailbox access).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_domain', 253)->storedAs("LOWER(SUBSTRING_INDEX(email, '@', -1))")->after('email')->index();
        });
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->string('email_domain', 253)->storedAs("LOWER(SUBSTRING_INDEX(email_address, '@', -1))")->after('email_address')->index();
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', fn (Blueprint $table) => $table->dropColumn('email_domain'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_domain'));
    }
};
