<?php

namespace App\Services\Uploads;

use App\Enums\VideoStatus;
use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use App\Services\Storage\VideoStorage;
use App\Services\Sync\UploadService;
use App\Support\ActivityLogger;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Adds a video file that is already on this server (Drive download, browser
 * upload, ZIP entry) to the current workspace: validated, de-duplicated by
 * SHA-256, stored under the workspace prefix and queued for processing.
 */
class VideoIngest
{
    public function __construct(
        private readonly VideoStorage $storage,
        private readonly UploadService $uploads,
        private readonly ActivityLogger $activity,
    ) {}

    public function store(string $path, string $filename, string $folder, string $source): IngestResult
    {
        $workspace = Tenant::workspace() ?? throw new \LogicException('Video ingest needs a workspace.');
        $filename = mb_substr(basename($filename), 0, 255);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, config('reelflow.uploads.allowed_extensions'), true)) {
            return new IngestResult(IngestResult::SKIPPED, message: 'Not a supported video type ('.implode(', ', config('reelflow.uploads.allowed_extensions')).').');
        }

        $size = (int) filesize($path);
        if ($size <= 0) {
            return new IngestResult(IngestResult::SKIPPED, message: 'The file is empty.');
        }
        if ($size > config('reelflow.uploads.max_file_size_mb') * 1024 * 1024) {
            return new IngestResult(IngestResult::SKIPPED, message: 'Larger than '.config('reelflow.uploads.max_file_size_mb').' MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        if (! str_starts_with($mime, 'video/') && $mime !== 'application/octet-stream') {
            return new IngestResult(IngestResult::SKIPPED, message: "Not a video file ({$mime}).");
        }

        $checksum = hash_file('sha256', $path);
        $existing = Video::where('checksum_sha256', $checksum)->first();
        if ($existing && ! $this->uploads->needsUpload($existing)) {
            return new IngestResult(IngestResult::DUPLICATE, $existing, 'Already in your library.');
        }
        if (! $workspace->hasStorageFor($size)) {
            return new IngestResult(IngestResult::QUOTA, message: "Storage limit of {$workspace->storage_limit_mb} MB reached. Delete videos or ask the administrator for more space.");
        }

        $key = $this->storage->keyFor($workspace->id, $checksum, $extension);
        $stream = fopen($path, 'rb');
        try {
            $this->storage->putStream($key, $stream, null, $mime);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $stored = ['storage_disk' => $this->storage->defaultDisk(), 'storage_key' => $key, 'mime_type' => $mime, 'uploaded_at' => now()];

        if ($existing) {
            // Registered by the sync agent but its upload never finished:
            // complete it with this copy instead of adding a duplicate.
            $existing->transitionTo(VideoStatus::Uploaded, $stored + ['processing_error' => null], "Completed from {$source}");
            ProcessVideoJob::dispatch($existing->id);

            return new IngestResult(IngestResult::ADDED, $existing);
        }

        try {
            $video = Video::create($stored + [
                'original_filename' => $filename,
                'folder' => mb_substr($folder, 0, 500),
                'checksum_sha256' => $checksum,
                'size_bytes' => $size,
                'status' => VideoStatus::Uploaded,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same file arrived concurrently by another route.
            return new IngestResult(IngestResult::DUPLICATE, Video::where('checksum_sha256', $checksum)->first(), 'Already in your library.');
        }

        $this->activity->log('video.imported', $video, "Added from {$source}");
        ProcessVideoJob::dispatch($video->id);

        return new IngestResult(IngestResult::ADDED, $video);
    }
}
