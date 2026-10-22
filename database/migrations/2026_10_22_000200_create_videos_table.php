<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sync_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('folder', 500)->default('')->index();
            $table->char('checksum_sha256', 64)->unique();
            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_key', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->decimal('frame_rate', 8, 3)->nullable();
            $table->string('video_codec', 40)->nullable();
            $table->string('audio_codec', 40)->nullable();
            $table->boolean('has_audio')->nullable();
            $table->string('thumbnail_disk', 40)->nullable();
            $table->string('thumbnail_key', 500)->nullable();
            $table->string('language', 20)->nullable();
            $table->string('category', 100)->nullable()->index();
            $table->string('topic')->nullable();
            $table->string('status', 20)->default('discovered')->index();
            $table->string('ai_status', 20)->default('pending')->index();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->boolean('allow_reuse')->default(false);
            $table->text('processing_error')->nullable();
            $table->json('validation_warnings')->nullable();
            $table->unsignedSmallInteger('upload_failures')->default(0);
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamp('storage_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'ai_status']);
        });

        // Every local copy of a video; lets the library show duplicates and
        // keeps the source-folder categorisation of each copy.
        Schema::create('video_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_device_id')->constrained()->cascadeOnDelete();
            $table->string('folder_label', 120);
            $table->string('relative_path', 1000);
            $table->char('path_hash', 64);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['sync_device_id', 'path_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_sources');
        Schema::dropIfExists('videos');
    }
};
