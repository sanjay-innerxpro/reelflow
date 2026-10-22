<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20);
            $table->string('external_id', 100);
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('account_type', 40);
            $table->foreignId('parent_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status', 20)->default('connected')->index();
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('auth_problem_notified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
