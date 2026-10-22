<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\ScheduledPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class CleanupTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    public function test_original_is_kept_until_every_enabled_account_has_posted_it(): void
    {
        $this->fakeInfrastructure();
        config(['reelflow.storage.cleanup_published_after_days' => 7]);
        [$fbPost, $video] = $this->duePost(Platform::Facebook, ['status' => PostStatus::Published, 'published_at' => now()->subDays(10)]);
        $instagram = $this->account(Platform::Instagram);

        $this->artisan('reels:cleanup')->assertSuccessful();
        Storage::disk('videos')->assertExists($video->storage_key);

        ScheduledPost::factory()->create([
            'video_id' => $video->id, 'social_account_id' => $instagram->id, 'platform' => Platform::Instagram,
            'status' => PostStatus::Published, 'published_at' => now()->subDays(9),
        ]);

        $this->artisan('reels:cleanup')->assertSuccessful();
        Storage::disk('videos')->assertMissing($video->storage_key);
        $this->assertNotNull($video->fresh()->storage_deleted_at);
    }

    public function test_nothing_is_deleted_when_cleanup_is_disabled(): void
    {
        $this->fakeInfrastructure();
        config(['reelflow.storage.cleanup_published_after_days' => null]);
        [, $video] = $this->duePost(Platform::Facebook, ['status' => PostStatus::Published, 'published_at' => now()->subDays(30)]);

        $this->artisan('reels:cleanup')->assertSuccessful();

        Storage::disk('videos')->assertExists($video->storage_key);
    }
}
