<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // "server" (FFmpeg on the server) or "agent" (sent by the sync agent,
            // for hosts without FFmpeg such as shared hosting).
            $table->string('media_source', 10)->nullable()->after('thumbnail_key');
            $table->json('ai_frame_keys')->nullable()->after('media_source');
            $table->timestamp('media_received_at')->nullable()->after('ai_frame_keys');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['media_source', 'ai_frame_keys', 'media_received_at']);
        });
    }
};
