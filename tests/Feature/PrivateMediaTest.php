<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class PrivateMediaTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    public function test_a_thumbnail_is_streamed_by_the_app_not_redirected_to_an_image_url(): void
    {
        config(['filesystems.disks.videos' => array_merge(config('filesystems.disks.videos'), [
            'driver' => 'local',
            'root' => storage_path('framework/testing/videos'),
            'serve' => true,
            'visibility' => 'private',
        ])]);
        Tenant::forget();
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);

        $video = Tenant::run($workspace, fn () => $this->readyVideo());
        Storage::disk('videos')->put($video->thumbnail_key ?? 'w1/thumbnails/x.jpg', 'JPEGDATA');
        $video->update(['thumbnail_key' => $video->thumbnail_key ?? 'w1/thumbnails/x.jpg', 'thumbnail_disk' => 'videos']);

        // Hosts that intercept image extensions (LiteSpeed on Hostinger) never
        // let a signed .jpg URL reach PHP, so the bytes come from the app.
        $response = $this->actingAs($user)->get(route('videos.thumbnail', $video));

        $response->assertOk();
        $response->assertHeader('content-type', 'image/jpeg');
        $this->assertSame('JPEGDATA', $response->streamedContent());
    }

    public function test_another_workspace_cannot_read_a_thumbnail(): void
    {
        Tenant::forget();
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        $intruder = User::factory()->create(['workspace_id' => $theirs->id]);

        $video = Tenant::run($mine, fn () => $this->readyVideo());

        $this->actingAs($intruder)->get(route('videos.thumbnail', $video))->assertNotFound();
    }
}
