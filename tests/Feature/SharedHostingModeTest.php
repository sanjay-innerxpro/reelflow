<?php

namespace Tests\Feature;

use App\Enums\AiStatus;
use App\Enums\VideoStatus;
use App\Models\SyncDevice;
use App\Models\User;
use App\Models\Video;
use App\Services\Ai\AiProviderFactory;
use App\Support\SystemCheck;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsFixtures;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * MEDIA_PROCESSING=agent and REELFLOW_QUEUE_MODE=cron: shared hosting
 * without FFmpeg, Redis or long-running processes.
 */
class SharedHostingModeTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        config(['reelflow.media.processing' => 'agent']);
        [, $this->token] = SyncDevice::issue('Laptop');
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    private function uploadVideo(string $content): int
    {
        $register = $this->api()->postJson('/api/sync/videos', [
            'filename' => 'clip.mp4', 'folder_label' => 'Reels', 'relative_path' => 'clip.mp4',
            'size' => strlen($content), 'checksum_sha256' => hash('sha256', $content),
        ])->assertCreated()->assertJsonPath('video.needs_media', true);
        $session = $register->json('upload.session_id');

        $this->api()->call('PUT', "/api/sync/uploads/{$session}/chunks/0", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_X_CHUNK_SHA256' => hash('sha256', $content),
        ], $content)->assertOk();
        $this->api()->postJson("/api/sync/uploads/{$session}/complete")->assertStatus(202);

        return $register->json('video.id');
    }

    private function sendMedia(int $videoId, array $meta = [], int $frames = 3)
    {
        return $this->api()->post("/api/sync/videos/{$videoId}/media", [
            'metadata' => json_encode(array_merge(['width' => 1080, 'height' => 1920, 'duration' => 24.5, 'frame_rate' => 30, 'video_codec' => 'h264', 'audio_codec' => 'aac', 'has_audio' => true], $meta)),
            'thumbnail' => UploadedFile::fake()->image('thumb.jpg', 270, 480),
            'frames' => array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg", 288, 512), range(1, $frames)),
        ]);
    }

    public function test_video_waits_for_agent_media_then_becomes_ready_without_server_ffmpeg(): void
    {
        $id = $this->uploadVideo($this->fakeVideoBytes(3000));

        $video = Video::find($id);
        $this->assertSame(VideoStatus::Uploaded, $video->status, 'No FFmpeg on the server: waits for the agent');
        $this->assertSame([], $this->media->calls);

        $this->sendMedia($id)->assertOk()->assertJsonPath('video.needs_media', false)->assertJsonPath('video.frames', 3);

        $video->refresh();
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame([1080, 1920, 24.5, 'agent'], [$video->width, $video->height, $video->duration_seconds, $video->media_source]);
        Storage::disk('videos')->assertExists($video->thumbnail_key);
        $this->assertCount(3, $video->ai_frame_keys);
        $this->assertSame([], $this->media->calls, 'Server never ran FFmpeg');
    }

    public function test_media_can_arrive_before_upload_completes(): void
    {
        $content = $this->fakeVideoBytes(2000);
        $register = $this->api()->postJson('/api/sync/videos', [
            'filename' => 'early.mp4', 'folder_label' => 'Reels', 'relative_path' => 'early.mp4',
            'size' => 2000, 'checksum_sha256' => hash('sha256', $content),
        ]);
        $id = $register->json('video.id');

        $this->sendMedia($id)->assertOk();
        $this->assertSame(VideoStatus::Uploading, Video::find($id)->status);

        $session = $register->json('upload.session_id');
        $this->api()->call('PUT', "/api/sync/uploads/{$session}/chunks/0", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_X_CHUNK_SHA256' => hash('sha256', $content),
        ], $content);
        $this->api()->postJson("/api/sync/uploads/{$session}/complete");

        $this->assertSame(VideoStatus::Ready, Video::find($id)->status, (string) Video::find($id)->processing_error);
    }

    public function test_agent_metadata_is_validated_like_server_metadata(): void
    {
        $id = $this->uploadVideo($this->fakeVideoBytes(1000));

        $this->sendMedia($id, ['duration' => 1.0])->assertOk();
        $this->assertSame(VideoStatus::Failed, Video::find($id)->status);
        $this->assertStringContainsString('Duration', Video::find($id)->processing_error);

        $this->api()->post("/api/sync/videos/{$id}/media", ['metadata' => '{"width": "wide"}', 'thumbnail' => UploadedFile::fake()->create('x.txt', 1)])
            ->assertStatus(422)->assertJsonValidationErrors(['thumbnail', 'metadata.width', 'metadata.height', 'metadata.duration']);
    }

    public function test_device_can_only_send_media_for_its_own_videos(): void
    {
        $other = Video::factory()->create();
        $this->sendMedia($other->id)->assertNotFound();
    }

    public function test_captions_use_agent_frames(): void
    {
        $provider = new FakeAiProvider;
        config(['reelflow.ai.provider' => 'fake']);
        app(AiProviderFactory::class)->extend('fake', $provider);

        $id = $this->uploadVideo($this->fakeVideoBytes(1500));
        $this->sendMedia($id, frames: 4)->assertOk();

        $video = Video::find($id);
        $this->assertSame(AiStatus::Completed, $video->ai_status);
        $this->assertCount(4, $provider->requests[0]->imagePaths);
        $this->assertSame([], $this->media->calls);
    }

    public function test_lookup_reports_videos_needing_media(): void
    {
        $id = $this->uploadVideo($this->fakeVideoBytes(500));
        $checksum = Video::find($id)->checksum_sha256;

        $this->api()->postJson('/api/sync/videos/lookup', ['checksums' => [$checksum]])->assertJsonPath("videos.{$checksum}.needs_media", true);
        $this->api()->postJson('/api/sync/heartbeat')->assertJsonPath('config.media_processing', 'agent')->assertJsonPath('config.ai_frames', 6);
    }

    public function test_server_mode_does_not_ask_for_agent_media(): void
    {
        config(['reelflow.media.processing' => 'server']);
        $this->assertFalse(Video::factory()->uploaded()->create()->needsAgentMedia());
    }

    public function test_system_check_accepts_missing_ffmpeg_in_agent_mode(): void
    {
        config(['reelflow.media.ffmpeg_path' => '/nonexistent/ffmpeg']);
        $this->assertSame('ok', app(SystemCheck::class)->all()['ffmpeg']['status']);

        config(['reelflow.media.processing' => 'server']);
        $this->assertSame('fail', app(SystemCheck::class)->all()['ffmpeg']['status']);
    }

    public function test_cron_queue_mode_schedules_workers_and_disables_horizon(): void
    {
        putenv('REELFLOW_QUEUE_MODE=cron');
        $_ENV['REELFLOW_QUEUE_MODE'] = $_SERVER['REELFLOW_QUEUE_MODE'] = 'cron';
        $this->refreshApplication();

        try {
            $this->artisan('schedule:list')
                ->expectsOutputToContain('queue:work sync --queue=notifications,default,media,ai --stop-when-empty')
                ->expectsOutputToContain('--queue=ingest,publish-youtube,publish-instagram,publish-facebook --stop-when-empty')
                ->doesntExpectOutputToContain('horizon:snapshot')
                ->assertSuccessful();

            $this->artisan('migrate');
            Tenant::forget(); // the refreshed in-memory database has no workspaces yet
            $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
        } finally {
            putenv('REELFLOW_QUEUE_MODE');
            unset($_ENV['REELFLOW_QUEUE_MODE'], $_SERVER['REELFLOW_QUEUE_MODE']);
        }
    }
}
