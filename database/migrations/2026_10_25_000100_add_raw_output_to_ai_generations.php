<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            // What the model actually returned: without it a failed generation
            // cannot be diagnosed after the fact.
            $table->text('raw_output')->nullable()->after('result');
            $table->unsignedTinyInteger('repair_attempts')->default(0)->after('raw_output');
        });
    }

    public function down(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->dropColumn(['raw_output', 'repair_attempts']);
        });
    }
};
