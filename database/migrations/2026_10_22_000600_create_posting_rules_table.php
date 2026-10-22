<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->boolean('enabled')->default(false);
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('daily_limit')->default(5);
            $table->time('window_start')->default('08:00:00');
            $table->time('window_end')->default('21:00:00');
            $table->unsignedSmallInteger('min_interval_minutes')->default(90);
            $table->json('slot_times')->nullable();
            $table->json('active_weekdays')->nullable();
            $table->unsignedSmallInteger('weekend_daily_limit')->nullable();
            $table->json('weekend_slot_times')->nullable();
            $table->unsignedSmallInteger('max_posts_per_period')->nullable();
            $table->unsignedSmallInteger('period_hours')->nullable();
            $table->boolean('auto_retry')->default(true);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->boolean('allow_reuse')->default(false);
            $table->string('selection_order', 20)->default('oldest_first');
            $table->json('folder_filter')->nullable();
            $table->string('privacy_status', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_rules');
    }
};
