<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Jobs\ProcessVideoJob;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\User;
use App\Models\Video;
use App\Services\Drive\GoogleDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class DriveImportTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    private const FOLDER = '1ABWJ5HhhYWD5b-Ijd46MJzX6jkl8Up74';

    /** @var array<string, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        Queue::fake([ProcessVideoJob::class]);
        $this->files = ['fileAAAAAAAAAA1' => $this->fakeVideoBytes(5000), 'fileBBBBBBBBBB2' => $this->fakeVideoBytes(7000)];
    }

    /**
     * @param  array<string, string>  $names  file id => file name
     */
    private function fakeDrive(array $names, array $overrides = []): void
    {
        $entries = collect($names)->map(fn ($name, $id) => '<div class="flip-entry"><a href="https://drive.google.com/file/d/'.$id.'/view?usp=drive_web" target="_blank"><div class="flip-entry-title">'.e($name).'</div></a></div>')->implode('');

        Http::fake(array_merge([
            'drive.google.com/embeddedfolderview*' => Http::response('<html><title>Reels</title><div class="flip-entries">'.$entries.'</div></html>'),
            'drive.usercontent.google.com/*' => function ($request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

                return isset($this->files[$query['id']])
                    ? Http::response($this->files[$query['id']], 200, ['Content-Type' => 'video/mp4'])
                    : Http::response('<html>Sign in</html>', 200, ['Content-Type' => 'text/html']);
            },
        ], $overrides));
    }

    private function folder(): DriveFolder
    {
        return DriveFolder::create(['folder_id' => self::FOLDER, 'name' => 'Gym reels']);
    }

    public function test_folder_id_is_parsed_from_links(): void
    {
        $this->assertSame(self::FOLDER, GoogleDriveClient::folderIdFrom('https://drive.google.com/drive/folders/'.self::FOLDER.'?usp=share_link'));
        $this->assertSame(self::FOLDER, GoogleDriveClient::folderIdFrom('https://drive.google.com/open?id='.self::FOLDER));
        $this->assertSame(self::FOLDER, GoogleDriveClient::folderIdFrom(self::FOLDER));
        $this->assertNull(GoogleDriveClient::folderIdFrom('https://example.com/not a folder'));
    }

    public function test_imports_new_videos_and_queues_processing(): void
    {
        $this->fakeDrive(['fileAAAAAAAAAA1' => ' (1).mp4', 'fileBBBBBBBBBB2' => 'clip two.mp4', 'fileCCCCCCCCCC3' => 'notes.pdf']);
        $folder = $this->folder();

        $this->artisan('reels:import-drive')->assertSuccessful();

        $this->assertSame(2, Video::count());
        $video = Video::where('original_filename', 'clip two.mp4')->firstOrFail();
        $this->assertSame(VideoStatus::Uploaded, $video->status);
        $this->assertSame('Gym reels', $video->folder);
        $this->assertSame(hash('sha256', $this->files['fileBBBBBBBBBB2']), $video->checksum_sha256);
        Storage::disk('videos')->assertExists($video->storage_key);
        Queue::assertPushed(ProcessVideoJob::class, 2);

        $this->assertSame(DriveFile::SKIPPED, DriveFile::where('file_id', 'fileCCCCCCCCCC3')->value('status'));
        $this->assertSame(2, $folder->files()->where('status', DriveFile::IMPORTED)->count());
        $this->assertNotNull($folder->fresh()->last_listed_at);
    }

    public function test_each_file_is_downloaded_once_and_existing_content_is_a_duplicate(): void
    {
        $existing = Video::factory()->create(['checksum_sha256' => hash('sha256', $this->files['fileAAAAAAAAAA1'])]);
        $this->fakeDrive(['fileAAAAAAAAAA1' => 'a.mp4', 'fileBBBBBBBBBB2' => 'b.mp4']);
        $this->folder();

        $this->artisan('reels:import-drive')->assertSuccessful();
        $this->artisan('reels:import-drive', ['--refresh' => true])->assertSuccessful();

        $this->assertSame(2, Video::count());
        $duplicate = DriveFile::where('file_id', 'fileAAAAAAAAAA1')->firstOrFail();
        $this->assertSame(DriveFile::DUPLICATE, $duplicate->status);
        $this->assertSame($existing->id, $duplicate->video_id);
        Http::assertSentCount(4); // 2 listings + 2 downloads, nothing downloaded twice
    }

    public function test_an_unfinished_agent_upload_is_completed_from_drive(): void
    {
        $content = $this->files['fileAAAAAAAAAA1'];
        $stuck = Video::factory()->create([
            'checksum_sha256' => hash('sha256', $content), 'size_bytes' => strlen($content),
            'status' => VideoStatus::Uploading, 'storage_key' => null, 'uploaded_at' => null,
        ]);
        $this->fakeDrive(['fileAAAAAAAAAA1' => 'a.mp4']);
        $this->folder();

        $this->artisan('reels:import-drive')->assertSuccessful();

        $this->assertSame(1, Video::count());
        $stuck->refresh();
        $this->assertSame(VideoStatus::Uploaded, $stuck->status);
        Storage::disk('videos')->assertExists($stuck->storage_key);
        $this->assertSame(DriveFile::IMPORTED, DriveFile::firstOrFail()->status);
        Queue::assertPushed(ProcessVideoJob::class, fn ($job) => $job->videoId === $stuck->id);
    }

    public function test_failed_downloads_are_retried_then_marked_failed(): void
    {
        $this->fakeDrive(['fileZZZZZZZZZZ9' => 'private.mp4']);
        $this->folder();

        foreach (range(1, 4) as $_) {
            $this->artisan('reels:import-drive')->assertSuccessful();
        }

        $file = DriveFile::firstOrFail();
        $this->assertSame(DriveFile::FAILED, $file->status);
        $this->assertSame(3, $file->attempts);
        $this->assertStringContainsString('web page instead of the file', $file->error);
        $this->assertSame(0, Video::count());
    }

    public function test_unreadable_folder_is_reported_on_the_folder(): void
    {
        $this->fakeDrive([], ['drive.google.com/embeddedfolderview*' => Http::response('Not found', 404)]);
        $folder = $this->folder();

        $this->artisan('reels:import-drive')->assertSuccessful();

        $this->assertStringContainsString('Folder not found', $folder->fresh()->last_error);
    }

    public function test_official_api_is_used_with_an_api_key(): void
    {
        config(['reelflow.drive.api_key' => 'test-key']);
        Http::fake([
            'www.googleapis.com/drive/v3/files?*' => Http::sequence()
                ->push(['files' => [['id' => 'fileAAAAAAAAAA1', 'name' => 'a.mp4', 'size' => '5000']], 'nextPageToken' => 'p2'])
                ->push(['files' => [['id' => 'fileBBBBBBBBBB2', 'name' => 'b.mp4', 'size' => '7000']]]),
            'www.googleapis.com/drive/v3/files/*' => fn ($request) => Http::response($this->files[basename(parse_url($request->url(), PHP_URL_PATH))], 200, ['Content-Type' => 'video/mp4']),
        ]);
        $this->folder();

        $this->artisan('reels:import-drive')->assertSuccessful();

        $this->assertSame(2, Video::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'pageToken=p2'));
    }

    public function test_drive_page_adds_and_lists_folders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/drive', ['link' => 'https://drive.google.com/drive/folders/'.self::FOLDER, 'name' => 'Gym reels'])
            ->assertRedirect(route('drive.index'));
        $this->actingAs($user)->post('/drive', ['link' => 'not a link', 'name' => 'x'])->assertSessionHasErrors('link');

        $this->actingAs($user)->get('/drive')->assertOk()->assertSee('Gym reels');
        $this->assertSame(1, DriveFolder::count());

        $this->artisan('reels:drive-add', ['link' => self::FOLDER, '--name' => 'Renamed'])->assertSuccessful();
        $this->assertSame('Renamed', DriveFolder::firstOrFail()->name);
    }
}
