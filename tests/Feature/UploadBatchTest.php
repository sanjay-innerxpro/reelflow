<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoJob;
use App\Models\UploadBatch;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;
use ZipArchive;

class UploadBatchTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        Queue::fake([ProcessVideoJob::class]);
        config(['reelflow.web_uploads.chunk_mb' => 1]);
        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, string>  $files  entry name => content
     */
    private function zip(array $files, int $method = ZipArchive::CM_STORE): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rfzip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
            $zip->setCompressionName($name, $method);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * Upload $bytes like the browser does and return the finished batch JSON.
     *
     * @return array<string, mixed>
     */
    private function upload(string $name, string $bytes): array
    {
        $this->actingAs($this->user);
        $batch = $this->postJson(route('uploads.store'), ['name' => $name, 'size' => strlen($bytes)])->assertCreated()->json();

        foreach (str_split($bytes, $batch['chunk_size']) as $index => $chunk) {
            $this->call('PUT', route('uploads.chunk', [$batch['id'], $index]), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], $chunk)
                ->assertOk();
        }

        return $this->postJson(route('uploads.complete', $batch['id']))->assertOk()->json();
    }

    public function test_zip_of_videos_is_unpacked_into_the_library(): void
    {
        $result = $this->upload('Summer reels.zip', $this->zip([
            'clips/one.mp4' => $this->fakeVideoBytes(1_500_000),
            'two.mov' => $this->fakeVideoBytes(4000),
            'three.MP4' => $this->fakeVideoBytes(5000),
            'notes.txt' => 'hello',
            '__MACOSX/clips/._one.mp4' => 'meta',
            '.DS_Store' => 'x',
        ]));

        $this->assertSame('completed', $result['status']);
        $this->assertSame(3, $result['added']);
        $this->assertEqualsCanonicalizing(
            ['one.mp4' => 'added', 'two.mov' => 'added', 'three.MP4' => 'added', 'notes.txt' => 'skipped'],
            collect($result['results'])->pluck('status', 'name')->all(),
        );
        $this->assertSame(3, Video::where('folder', 'Uploads/Summer reels')->count());
        Video::all()->each(fn (Video $v) => Storage::disk('videos')->assertExists($v->storage_key));
        Queue::assertPushed(ProcessVideoJob::class, 3);
        $this->assertSame([], Storage::disk('local')->allFiles('web-uploads'), 'Chunks are removed after unpacking');
    }

    public function test_single_video_upload_and_duplicates(): void
    {
        $video = $this->fakeVideoBytes(2_200_000);

        $this->assertSame('added', $this->upload('clip.mp4', $video)['results'][0]['status']);
        $again = $this->upload('clip-copy.mp4', $video);

        $this->assertSame('duplicate', $again['results'][0]['status']);
        $this->assertSame(1, Video::count());
    }

    public function test_zip_limits_are_enforced(): void
    {
        config(['reelflow.web_uploads.max_videos' => 2]);
        $tooMany = $this->upload('many.zip', $this->zip(['a.mp4' => $this->fakeVideoBytes(100), 'b.mp4' => $this->fakeVideoBytes(100), 'c.mp4' => $this->fakeVideoBytes(100)]));
        $this->assertSame('failed', $tooMany['status']);
        $this->assertStringContainsString('limit is 2 per upload', $tooMany['error']);

        $empty = $this->upload('docs.zip', $this->zip(['readme.txt' => 'x']));
        $this->assertStringContainsString('No videos were found', $empty['error']);

        $broken = $this->upload('broken.zip', 'not really a zip file');
        $this->assertStringContainsString('not a valid ZIP', $broken['error']);
        $this->assertSame(0, Video::count());
    }

    public function test_highly_compressed_entries_are_not_unpacked(): void
    {
        // 5 MB of zeros compresses ~1000:1, like a zip bomb.
        $result = $this->upload('bomb.zip', $this->zip(['big.mp4' => str_repeat("\0", 5_000_000), 'ok.mp4' => $this->fakeVideoBytes(3000)], ZipArchive::CM_DEFLATE));

        $statuses = collect($result['results'])->pluck('status', 'name');
        $this->assertSame('skipped', $statuses['big.mp4']);
        $this->assertSame(1, Video::count());
    }

    public function test_size_type_and_storage_limits_are_checked_before_uploading(): void
    {
        $this->actingAs($this->user);
        $this->postJson(route('uploads.store'), ['name' => 'huge.zip', 'size' => 201 * 1024 * 1024])->assertStatus(422)->assertJsonPath('error', 'invalid_size');
        $this->postJson(route('uploads.store'), ['name' => 'virus.exe', 'size' => 100])->assertStatus(422)->assertJsonPath('error', 'invalid_extension');

        $this->workspace->update(['storage_limit_mb' => 1]);
        $this->actingAs($this->user->fresh()); // a new request loads the workspace afresh
        $this->postJson(route('uploads.store'), ['name' => 'clip.mp4', 'size' => 2 * 1024 * 1024])->assertStatus(422)->assertJsonPath('error', 'storage_limit');
    }

    public function test_chunks_are_validated_and_completion_needs_every_chunk(): void
    {
        $this->actingAs($this->user);
        $batch = $this->postJson(route('uploads.store'), ['name' => 'clip.mp4', 'size' => 1_500_000])->assertCreated()->json();
        $put = fn (int $index, string $body) => $this->call('PUT', route('uploads.chunk', [$batch['id'], $index]), [], [], [], ['HTTP_ACCEPT' => 'application/json'], $body);

        $put(0, 'too short')->assertStatus(422)->assertJsonPath('error', 'chunk_size_mismatch');
        $put(5, 'x')->assertStatus(422)->assertJsonPath('error', 'invalid_chunk');
        $put(0, str_repeat('a', 1024 * 1024))->assertOk();

        $this->getJson(route('uploads.show', $batch['id']))->assertJsonPath('received', [0]);
        $this->postJson(route('uploads.complete', $batch['id']))->assertStatus(409)->assertJsonPath('error', 'upload_incomplete');
    }

    public function test_uploads_are_private_to_their_workspace(): void
    {
        $this->actingAs($this->user);
        $batch = $this->postJson(route('uploads.store'), ['name' => 'clip.mp4', 'size' => 100])->json();
        $other = User::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

        $this->actingAs($other)->getJson(route('uploads.show', $batch['id']))->assertNotFound();
        $this->actingAs($other)->call('PUT', route('uploads.chunk', [$batch['id'], 0]), [], [], [], ['HTTP_ACCEPT' => 'application/json'], str_repeat('a', 100))->assertNotFound();
        $this->actingAs($this->user)->get(route('uploads.index'))->assertOk()->assertSee('clip.mp4');
    }

    public function test_cleanup_expires_abandoned_uploads(): void
    {
        $this->actingAs($this->user);
        $batch = $this->postJson(route('uploads.store'), ['name' => 'clip.mp4', 'size' => 100])->json();
        $this->call('PUT', route('uploads.chunk', [$batch['id'], 0]), [], [], [], ['HTTP_ACCEPT' => 'application/json'], str_repeat('a', 100));
        UploadBatch::whereKey($batch['id'])->update(['expires_at' => now()->subMinute()]);

        $this->artisan('reels:cleanup')->assertSuccessful();

        $this->assertSame(UploadBatch::EXPIRED, UploadBatch::find($batch['id'])->status);
        $this->assertSame([], Storage::disk('local')->allFiles('web-uploads'));
    }
}
