<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->restrictOnDelete();
            $table->foreignId('social_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_content_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20);
            // 0 for the first post of a video on an account; incremented only
            // when a rule or a user explicitly allows the video to be reused.
            $table->unsignedSmallInteger('reuse_sequence')->default(0);
            $table->dateTime('scheduled_at')->index();
            $table->string('status', 20)->default('scheduled');
            $table->string('stage', 30)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->string('external_upload_id')->nullable();
            $table->string('external_container_id')->nullable();
            $table->text('upload_session_url')->nullable();
            $table->string('external_post_id')->nullable();
            $table->string('post_url', 500)->nullable();
            $table->string('last_error_category', 30)->nullable();
            $table->text('last_error_message')->nullable();
            $table->boolean('requires_review')->default(false);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('publishing_started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('metrics_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['video_id', 'social_account_id', 'reuse_sequence'], 'scheduled_posts_video_account_seq_unique');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['social_account_id', 'scheduled_at']);
        });

        Schema::create('publish_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_post_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('stage', 30)->nullable();
            $table->dateTime('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_meta')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->string('error_category', 30)->nullable();
            $table->text('error_message')->nullable();
            $table->string('retry_decision', 20)->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();

            $table->unique(['scheduled_post_id', 'attempt_number']);
        });

        Schema::create('post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_post_id')->constrained()->cascadeOnDelete();
            $table->dateTime('captured_at')->index();
            // NULL means "not available from this platform/permission", never zero.
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->decimal('avg_watch_seconds', 10, 2)->nullable();
            $table->unsignedBigInteger('total_watch_seconds')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metrics');
        Schema::dropIfExists('publish_attempts');
        Schema::dropIfExists('scheduled_posts');
    }
};
