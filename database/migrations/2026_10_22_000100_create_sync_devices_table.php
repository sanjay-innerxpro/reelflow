<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('device_uuid')->unique();
            $table->string('name');
            $table->char('token_hash', 64)->unique();
            $table->string('token_hint', 12);
            $table->string('status', 20)->default('active')->index();
            $table->string('agent_version', 40)->nullable();
            $table->string('platform_info', 120)->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('offline_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_devices');
    }
};
