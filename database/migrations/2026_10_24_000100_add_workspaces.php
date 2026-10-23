<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy: every customer gets a workspace and every tenant-owned row
 * belongs to one. Existing data moves into the first user's workspace, which
 * keeps no limits (it is the operator's own).
 */
return new class extends Migration
{
    /** Tenant-owned tables. workspace_id stays nullable in the schema; the
     *  application always sets it (rows without one are visible to nobody). */
    private const TABLES = [
        'sync_devices', 'videos', 'video_sources', 'upload_sessions', 'social_accounts', 'posting_rules',
        'scheduled_posts', 'publish_attempts', 'post_metrics', 'ai_generations', 'ai_contents',
        'app_settings', 'activity_logs', 'notification_logs', 'drive_folders', 'drive_files',
    ];

    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('storage_limit_mb')->default(2048);
            $table->unsignedSmallInteger('max_accounts')->default(5);
            $table->unsignedInteger('daily_ai_limit')->default(50);
            $table->dateTime('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_admin')->default(false)->after('password');
            $table->dateTime('last_login_at')->nullable()->after('remember_token');
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        $this->backfill();

        Schema::table('videos', function (Blueprint $table) {
            $table->dropUnique(['checksum_sha256']);
            $table->unique(['workspace_id', 'checksum_sha256']);
        });
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['workspace_id', 'key']);
        });
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique(['platform', 'external_id']);
            $table->unique(['workspace_id', 'platform', 'external_id']);
        });
        Schema::table('drive_folders', function (Blueprint $table) {
            $table->dropUnique(['folder_id']);
            $table->unique(['workspace_id', 'folder_id']);
        });
    }

    private function backfill(): void
    {
        $hasData = DB::table('users')->exists()
            || collect(['videos', 'social_accounts', 'sync_devices', 'app_settings'])->contains(fn ($t) => DB::table($t)->exists());
        if (! $hasData) {
            return;
        }

        $owner = DB::table('users')->orderBy('id')->first();
        $now = now();
        $workspaceId = DB::table('workspaces')->insertGetId([
            'name' => $owner ? $owner->name."'s workspace" : 'Main workspace',
            'owner_id' => $owner?->id,
            'storage_limit_mb' => 0,
            'max_accounts' => 0,
            'daily_ai_limit' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->update(['workspace_id' => $workspaceId]);
        if ($owner) {
            DB::table('users')->where('id', $owner->id)->update(['is_admin' => true]);
        }
        foreach (self::TABLES as $name) {
            DB::table($name)->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);
        }
    }

    public function down(): void
    {
        Schema::table('drive_folders', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'folder_id']);
            $table->unique(['folder_id']);
        });
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'platform', 'external_id']);
            $table->unique(['platform', 'external_id']);
        });
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'key']);
            $table->unique(['key']);
        });
        Schema::table('videos', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'checksum_sha256']);
            $table->unique(['checksum_sha256']);
        });

        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('workspace_id');
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['is_admin', 'last_login_at']);
        });
        Schema::dropIfExists('workspaces');
    }
};
