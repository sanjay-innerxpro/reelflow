<?php

namespace App\Services\Media;

use App\Enums\VideoStatus;
use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use App\Services\Storage\VideoStorage;
use App\Support\ActivityLogger;
use Illuminate\Http\UploadedFile;

/**
 * Stores metadata, thumbnail and AI frames produced by the sync agent's
 * local FFmpeg (MEDIA_PROCESSING=agent, for hosts without FFmpeg).
 */
class AgentMediaService
{
    public function __construct(
        private readonly VideoStorage $storage,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{width: int, height: int, duration: float, frame_rate?: ?float, video_codec?: ?string, audio_codec?: ?string, has_audio?: bool}  $meta
     * @param  array<int, UploadedFile>  $frames
     */
    public function store(Video $video, array $meta, UploadedFile $thumbnail, array $frames): Video
    {
        $thumbDisk = config('reelflow.storage.thumbnail_disk');
        $thumbKey = $this->storage->thumbnailKeyFor($video);
        $this->put($thumbKey, $thumbnail, $thumbDisk);

        foreach ($video->ai_frame_keys ?? [] as $old) {
            $this->storage->delete($old, $video->storage_disk ?? $this->storage->defaultDisk());
        }

        $frameKeys = [];
        foreach (array_values($frames) as $i => $frame) {
            $key = sprintf('frames/%s/%s/%02d.jpg', substr($video->checksum_sha256, 0, 2), $video->checksum_sha256, $i);
            $this->put($key, $frame, $video->storage_disk ?? $this->storage->defaultDisk());
            $frameKeys[] = $key;
        }

        $video->fill([
            'width' => (int) $meta['width'],
            'height' => (int) $meta['height'],
            'duration_seconds' => round((float) $meta['duration'], 3),
            'frame_rate' => isset($meta['frame_rate']) ? round((float) $meta['frame_rate'], 3) : null,
            'video_codec' => isset($meta['video_codec']) ? mb_substr((string) $meta['video_codec'], 0, 40) : null,
            'audio_codec' => isset($meta['audio_codec']) ? mb_substr((string) $meta['audio_codec'], 0, 40) : null,
            'has_audio' => (bool) ($meta['has_audio'] ?? false),
            'thumbnail_disk' => $thumbDisk,
            'thumbnail_key' => $thumbKey,
            'ai_frame_keys' => $frameKeys,
            'media_source' => 'agent',
            'media_received_at' => now(),
        ])->save();

        $this->activity->log('video.media_received', $video, sprintf('Metadata and %d frame(s) received from the sync agent', count($frameKeys)));

        // First time (or after a failure): run validation and AI captioning.
        if (in_array($video->status, [VideoStatus::Uploaded, VideoStatus::Failed], true) && $video->uploaded_at !== null) {
            ProcessVideoJob::dispatch($video->id);
        }

        return $video->refresh();
    }

    private function put(string $key, UploadedFile $file, string $disk): void
    {
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $this->storage->putStream($key, $stream, $disk, 'image/jpeg');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
