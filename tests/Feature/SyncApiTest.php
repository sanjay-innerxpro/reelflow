<?php

namespace Tests\Feature;

use App\Enums\UploadSessionStatus;
use App\Enums\VideoStatus;
use App\Models\SyncDevice;
use App\Models\UploadSession;
use App\Models\Video;
use App\Notifications\UploadFailuresNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    private string $token;

    private SyncDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        [$this->device, $this->token] = SyncDevice::issue('Test PC');
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    private function register(string $content, string $path = 'recipes/dal.mp4', string $label = 'Cooking')
    {
        return $this->api()->postJson('/api/sync/videos', [
            'filename' => basename($path),
            'folder_label' => $label,
            'relative_path' => $path,
            'size' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
        ]);
    }

    private function putChunk(string $sessionId, int $index, string $bytes, ?string $sha = null)
    {
        return $this->api()->call('PUT', "/api/sync/uploads/{$sessionId}/chunks/{$index}", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHUNK_SHA256' => $sha ?? hash('sha256', $bytes),
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $bytes);
    }

    public function test_requests_without_valid_device_token_are_rejected(): void
    {
        $this->postJson('/api/sync/heartbeat')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer rfd_wrong')->postJson('/api/sync/heartbeat')->assertStatus(401);

        $this->device->revoke();
        $this->api()->postJson('/api/sync/heartbeat')->assertStatus(403)->assertJson(['error' => 'device_revoked']);
    }

    public function test_heartbeat_updates_device_and_returns_config(): void
    {
        $this->api()->postJson('/api/sync/heartbeat', ['agent_version' => '1.0.0', 'platform' => 'Linux', 'last_error' => null])
            ->assertOk()
            ->assertJsonPath('config.chunk_size_bytes', 1024 * 1024)
            ->assertJsonPath('device.name', 'Test PC');

        $this->assertSame('1.0.0', $this->device->fresh()->agent_version);
        $this->assertNotNull($this->device->fresh()->last_seen_at);
    }

    public function test_full_chunked_upload_stores_file_privately_and_starts_processing(): void
    {
        $content = $this->fakeVideoBytes(1024 * 1024 + 500); // two chunks with 1 MB chunk size
        $response = $this->register($content)->assertCreated()
            ->assertJsonPath('video.duplicate', false)
            ->assertJsonPath('upload.total_chunks', 2);
        $sessionId = $response->json('upload.session_id');

        $this->putChunk($sessionId, 0, substr($content, 0, 1024 * 1024))->assertOk()->assertJsonPath('received_count', 1);
        $this->putChunk($sessionId, 1, substr($content, 1024 * 1024))->assertOk()->assertJsonPath('received_count', 2);
        $this->api()->postJson("/api/sync/uploads/{$sessionId}/complete")->assertStatus(202);

        $video = Video::first();
        // Queue is synchronous in tests: assembly → processing → ready (no AI configured).
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame('Cooking/recipes', $video->folder);
        Storage::disk('videos')->assertExists($video->storage_key);
        $this->assertSame(hash('sha256', $content), hash('sha256', Storage::disk('videos')->get($video->storage_key)));
        $this->assertSame(1920, $video->height);
        $this->assertNotNull($video->thumbnail_key);
        $this->assertSame(UploadSessionStatus::Completed, UploadSession::first()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('upload-chunks'), 'Chunks are cleaned up');
    }

    public function test_duplicate_checksum_is_not_uploaded_twice_but_source_is_recorded(): void
    {
        $content = $this->fakeVideoBytes(2000);
        $sessionId = $this->register($content)->json('upload.session_id');
        $this->putChunk($sessionId, 0, $content)->assertOk();
        $this->api()->postJson("/api/sync/uploads/{$sessionId}/complete")->assertStatus(202);

        $this->register($content, 'copies/dal-copy.mp4', 'Backup')
            ->assertOk()
            ->assertJsonPath('video.duplicate', true)
            ->assertJsonPath('video.uploaded', true)
            ->assertJsonPath('upload', null);

        $this->assertSame(1, Video::count());
        $this->assertSame(2, Video::first()->sources()->count());
    }

    public function test_interrupted_upload_resumes_with_same_session(): void
    {
        $content = $this->fakeVideoBytes(1024 * 1024 + 10);
        $first = $this->register($content)->json('upload');
        $this->putChunk($first['session_id'], 0, substr($content, 0, 1024 * 1024))->assertOk();

        // Agent restarts and registers the same file again.
        $resumed = $this->register($content)->assertOk()->json('upload');
        $this->assertSame($first['session_id'], $resumed['session_id']);
        $this->assertSame([0], $resumed['received_chunks']);

        $this->api()->postJson("/api/sync/uploads/{$first['session_id']}/complete")
            ->assertStatus(409)->assertJson(['error' => 'upload_incomplete']);

        $this->putChunk($first['session_id'], 1, substr($content, 1024 * 1024))->assertOk();
        $this->api()->postJson("/api/sync/uploads/{$first['session_id']}/complete")->assertStatus(202);
        $this->assertSame(VideoStatus::Ready, Video::first()->status);
    }

    public function test_chunk_with_wrong_checksum_or_size_is_rejected(): void
    {
        $content = $this->fakeVideoBytes(3000);
        $sessionId = $this->register($content)->json('upload.session_id');

        $this->putChunk($sessionId, 0, $content, str_repeat('a', 64))->assertStatus(422)->assertJson(['error' => 'chunk_checksum_mismatch']);
        $this->putChunk($sessionId, 0, substr($content, 0, 100))->assertStatus(422)->assertJson(['error' => 'chunk_size_mismatch']);
        $this->putChunk($sessionId, 5, $content)->assertStatus(422)->assertJson(['error' => 'invalid_chunk']);
    }

    public function test_assembled_file_with_wrong_checksum_fails_and_can_be_reuploaded(): void
    {
        Notification::fake();
        config(['reelflow.uploads.repeated_failure_threshold' => 1]);

        $content = $this->fakeVideoBytes(2000);
        // Register with a checksum that does not match the bytes we send.
        $sessionId = $this->api()->postJson('/api/sync/videos', [
            'filename' => 'x.mp4', 'folder_label' => 'F', 'relative_path' => 'x.mp4',
            'size' => 2000, 'checksum_sha256' => hash('sha256', 'something else'),
        ])->json('upload.session_id');
        $this->putChunk($sessionId, 0, $content)->assertOk();
        $this->api()->postJson("/api/sync/uploads/{$sessionId}/complete")->assertStatus(202);

        $video = Video::first();
        $this->assertSame(VideoStatus::Failed, $video->status);
        $this->assertSame(1, $video->upload_failures);
        Notification::assertSentOnDemand(UploadFailuresNotification::class);

        // A new registration opens a fresh session for the failed video.
        $retry = $this->api()->postJson('/api/sync/videos', [
            'filename' => 'x.mp4', 'folder_label' => 'F', 'relative_path' => 'x.mp4',
            'size' => 2000, 'checksum_sha256' => hash('sha256', 'something else'),
        ])->assertOk()->json('upload.session_id');
        $this->assertNotSame($sessionId, $retry);
        $this->assertSame(VideoStatus::Uploading, $video->fresh()->status);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $this->register('abc', '/etc/passwd.mp4')->assertStatus(422)->assertJson(['error' => 'invalid_path']);
        $this->register('abc', '../up/x.mp4')->assertStatus(422);
        $this->register('abc', 'notes.txt')->assertStatus(422)->assertJson(['error' => 'invalid_extension']);
        $this->api()->postJson('/api/sync/videos', ['filename' => 'a.mp4'])->assertStatus(422);
    }

    public function test_device_cannot_access_another_devices_session(): void
    {
        $sessionId = $this->register($this->fakeVideoBytes(100))->json('upload.session_id');
        [, $otherToken] = SyncDevice::issue('Other');

        $this->withHeaders(['Authorization' => 'Bearer '.$otherToken])->getJson("/api/sync/uploads/{$sessionId}")->assertNotFound();
    }

    public function test_lookup_reports_known_checksums(): void
    {
        $video = Video::factory()->create();
        $this->api()->postJson('/api/sync/videos/lookup', ['checksums' => [$video->checksum_sha256, str_repeat('f', 64)]])
            ->assertOk()
            ->assertJsonPath("videos.{$video->checksum_sha256}.video_id", $video->id)
            ->assertJsonMissingPath('videos.'.str_repeat('f', 64));
    }
}
