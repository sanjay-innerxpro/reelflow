<?php

namespace App\Services\Media;

use App\Enums\AiStatus;
use App\Enums\VideoStatus;
use App\Jobs\GenerateCaptionsJob;
use App\Models\Video;
use App\Services\Ai\AiProviderFactory;
use App\Services\Storage\VideoStorage;
use App\Support\Settings;

/**
 * Metadata extraction, thumbnail generation and validation for a stored video.
 */
class VideoProcessor
{
    public function __construct(
        private readonly VideoStorage $storage,
        private readonly MediaToolkit $media,
        private readonly PlatformRequirements $requirements,
        private readonly Settings $settings,
        private readonly AiProviderFactory $ai,
    ) {}

    public function process(Video $video): void
    {
        if ($video->status !== VideoStatus::Processing) {
            $video->transitionTo(VideoStatus::Processing, ['processing_started_at' => now(), 'processing_error' => null]);
        }

        if (! $this->storage->exists($video)) {
            $this->fail($video, 'The video file is missing from storage.');

            return;
        }

        $local = $this->storage->downloadToTemp($video);
        $thumb = $this->storage->tempPath('jpg');

        try {
            $meta = $this->media->probe($local);

            if (! $meta->hasVideo || ! $meta->width || ! $meta->height) {
                $this->fail($video, 'No playable video stream was found.');

                return;
            }

            $min = config('reelflow.media.min_duration_seconds');
            $max = config('reelflow.media.max_duration_seconds');
            if ($meta->duration === null || $meta->duration < $min || $meta->duration > $max) {
                $this->fail($video, sprintf('Duration %.1fs is outside the allowed %.0f–%.0fs range.', $meta->duration ?? 0, $min, $max));

                return;
            }

            $this->media->thumbnail($local, $thumb, min(1.0, $meta->duration / 2));
            $thumbKey = $this->storage->thumbnailKeyFor($video);
            $stream = fopen($thumb, 'rb');
            $this->storage->putStream($thumbKey, $stream, config('reelflow.storage.thumbnail_disk'), 'image/jpeg');
            if (is_resource($stream)) {
                fclose($stream);
            }

            $video->fill([
                'width' => $meta->width,
                'height' => $meta->height,
                'duration_seconds' => $meta->duration,
                'frame_rate' => $meta->frameRate,
                'video_codec' => $meta->videoCodec,
                'audio_codec' => $meta->audioCodec,
                'has_audio' => $meta->hasAudio,
                'thumbnail_disk' => config('reelflow.storage.thumbnail_disk'),
                'thumbnail_key' => $thumbKey,
                'processed_at' => now(),
            ]);
            $video->validation_warnings = $this->requirements->allProblems($video) ?: null;
            $video->save();
        } catch (MediaToolException $e) {
            $this->fail($video, 'Media processing failed: '.$e->getMessage());

            return;
        } finally {
            @unlink($local);
            @unlink($thumb);
        }

        $this->afterMetadata($video);
    }

    /**
     * Hand off to AI captioning, or mark the video ready when AI is not in use.
     */
    public function afterMetadata(Video $video): void
    {
        if ($this->settings->bool('ai.auto_generate') && $this->ai->isConfigured()) {
            $video->update(['ai_status' => AiStatus::Pending]);
            GenerateCaptionsJob::dispatch($video->id);

            return;
        }

        $video->transitionTo(VideoStatus::Ready, [
            'ai_status' => $video->ai_status === AiStatus::Pending ? AiStatus::Skipped : $video->ai_status,
        ], 'Ready (AI captioning not configured or disabled)');
    }

    private function fail(Video $video, string $reason): void
    {
        $video->transitionTo(VideoStatus::Failed, ['processing_error' => $reason], $reason);
    }
}
