<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 20)->default('system');
            $table->string('action', 80)->index();
            $table->nullableMorphs('subject');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('level', 10)->default('info')->index();
            $table->string('message', 500)->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at')->index();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 80)->index();
            $table->string('channel', 20)->default('mail');
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('status', 20)->index();
            $table->nullableMorphs('related');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('app_settings');
    }
};
