<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_device_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->string('status', 20)->default('open')->index();
            $table->text('error')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('upload_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('upload_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->unique(['upload_session_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_chunks');
        Schema::dropIfExists('upload_sessions');
    }
};
