<?php

namespace Tests\Feature;

use App\Enums\AiStatus;
use App\Enums\VideoStatus;
use App\Jobs\ProcessVideoJob;
use App\Services\Media\VideoMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class VideoProcessingTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
    }

    public function test_metadata_and_thumbnail_are_extracted(): void
    {
        $video = $this->readyVideo(['status' => VideoStatus::Uploaded, 'ai_status' => AiStatus::Pending, 'width' => null, 'height' => null, 'duration_seconds' => null]);
        $this->media->metadata = new VideoMetadata(720, 1280, 95.0, 25.0, 'hevc', null, true, false);

        ProcessVideoJob::dispatch($video->id);

        $video->refresh();
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame(AiStatus::Skipped, $video->ai_status);
        $this->assertSame([720, 1280, 95.0, false], [$video->width, $video->height, $video->duration_seconds, $video->has_audio]);
        Storage::disk('videos')->assertExists($video->thumbnail_key);
        // 95s exceeds Facebook Reels' 90s limit; recorded as a warning, not a failure.
        $this->assertArrayHasKey('facebook', $video->validation_warnings);
        $this->assertArrayNotHasKey('instagram', $video->validation_warnings);
    }

    public function test_invalid_duration_fails_processing(): void
    {
        $video = $this->readyVideo(['status' => VideoStatus::Uploaded]);
        $this->media->metadata = new VideoMetadata(1080, 1920, 1.0, 30.0, 'h264', null, true, false);

        ProcessVideoJob::dispatch($video->id);

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);
        $this->assertStringContainsString('Duration', $video->fresh()->processing_error);
    }

    public function test_ffmpeg_failure_is_recorded(): void
    {
        $video = $this->readyVideo(['status' => VideoStatus::Uploaded]);
        $this->media->fail = true;

        ProcessVideoJob::dispatch($video->id);

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);
        $this->assertStringContainsString('ffprobe exploded', $video->fresh()->processing_error);
    }

    public function test_missing_file_is_detected_before_processing(): void
    {
        $video = $this->readyVideo(['status' => VideoStatus::Uploaded], withFile: false);

        ProcessVideoJob::dispatch($video->id);

        $this->assertSame('The video file is missing from storage.', $video->fresh()->processing_error);
    }
}
