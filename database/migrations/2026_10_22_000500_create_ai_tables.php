<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->string('provider', 40);
            $table->string('model', 100)->nullable();
            $table->string('prompt_version', 20);
            $table->unsignedSmallInteger('frame_count')->default(0);
            $table->boolean('used_transcript')->default(false);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20);
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->text('description')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('tags')->nullable();
            $table->string('language', 20)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('validation_status', 20)->index();
            $table->json('content_warnings')->nullable();
            $table->boolean('is_current')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_generation_id', 'platform']);
            $table->index(['video_id', 'platform', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_contents');
        Schema::dropIfExists('ai_generations');
    }
};
