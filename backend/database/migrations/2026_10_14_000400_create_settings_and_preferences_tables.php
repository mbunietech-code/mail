<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->ulid('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->enum('theme', ['system', 'light', 'dark'])->default('system');
            $table->enum('density', ['comfortable', 'compact'])->default('comfortable');
            $table->ulid('default_account_id')->nullable();
            $table->enum('remote_images', ['never', 'ask', 'trusted_senders'])->default('ask');
            $table->boolean('show_subject_in_notifications')->default(false);
            $table->json('trusted_image_senders')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('settings');
    }
};
