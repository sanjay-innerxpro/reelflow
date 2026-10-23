<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Google Drive folders the server imports videos from (no computer needed).
        Schema::create('drive_folders', function (Blueprint $table) {
            $table->id();
            $table->string('folder_id', 100)->unique();
            $table->string('name', 120);
            $table->boolean('enabled')->default(true);
            $table->dateTime('last_listed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // One row per file seen in a folder, so each file is downloaded once.
        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_folder_id')->constrained()->cascadeOnDelete();
            $table->string('file_id', 100);
            $table->string('name');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['drive_folder_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('drive_folders');
    }
};
