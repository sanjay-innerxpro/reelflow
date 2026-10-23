<?php

namespace App\Services\Storage;

use App\Models\Video;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single entry point for private video storage. Works with any Laravel disk;
 * production should point VIDEO_STORAGE_DISK at a private S3-compatible bucket.
 */
class VideoStorage
{
    public function disk(?string $name = null): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk($name ?? $this->defaultDisk());
    }

    public function defaultDisk(): string
    {
        return config('reelflow.storage.disk');
    }

    /**
     * Every workspace stores under its own prefix, so identical files of two
     * workspaces never share (or delete) one object.
     */
    public function keyFor(int $workspaceId, string $checksum, string $extension): string
    {
        return sprintf('w%d/originals/%s/%s/%s.%s', $workspaceId, substr($checksum, 0, 2), substr($checksum, 2, 2), $checksum, strtolower($extension));
    }

    public function thumbnailKeyFor(Video $video): string
    {
        return sprintf('w%d/thumbnails/%s/%s.jpg', $video->workspace_id, substr($video->checksum_sha256, 0, 2), $video->checksum_sha256);
    }

    public function frameKeyFor(Video $video, int $index): string
    {
        return sprintf('w%d/frames/%s/%s/%02d.jpg', $video->workspace_id, substr($video->checksum_sha256, 0, 2), $video->checksum_sha256, $index);
    }

    /**
     * @param  resource  $stream
     */
    public function putStream(string $key, $stream, ?string $disk = null, ?string $mime = null): void
    {
        $options = ['visibility' => 'private'];
        if ($mime) {
            $options['ContentType'] = $mime;
        }

        if (! $this->disk($disk)->writeStream($key, $stream, $options)) {
            throw new RuntimeException("Failed to write {$key} to storage.");
        }
    }

    public function exists(Video $video): bool
    {
        return $video->storage_key !== null && $this->disk($video->storage_disk)->exists($video->storage_key);
    }

    public function size(Video $video): int
    {
        return $this->disk($video->storage_disk)->size($video->storage_key);
    }

    /**
     * @return resource
     */
    public function readStream(Video $video)
    {
        $stream = $this->disk($video->storage_disk)->readStream($video->storage_key);
        if (! is_resource($stream)) {
            throw new RuntimeException('Video file is not readable from storage.');
        }

        return $stream;
    }

    /**
     * Short-lived signed URL for platforms that fetch the file themselves.
     * Callers should request a fresh URL for each remote request rather than
     * caching one, so an expired URL is never handed to a platform.
     */
    public function temporaryUrl(Video $video, ?CarbonInterface $expiresAt = null, ?string $key = null, ?string $disk = null): string
    {
        $disk = $this->disk($disk ?? $video->storage_disk);
        $expiresAt ??= now()->addMinutes(config('reelflow.storage.signed_url_ttl_minutes'));

        if (! $disk->providesTemporaryUrls()) {
            throw new RuntimeException('The configured storage disk cannot generate signed URLs.');
        }

        return $disk->temporaryUrl($key ?? $video->storage_key, $expiresAt);
    }

    public function thumbnailUrl(Video $video, int $minutes = 60): ?string
    {
        if (! $video->thumbnail_key) {
            return null;
        }

        try {
            return $this->disk($video->thumbnail_disk)->temporaryUrl($video->thumbnail_key, now()->addMinutes($minutes));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Copy a stored video to a local temporary file (needed by FFmpeg).
     * The caller must unlink the returned path.
     */
    public function downloadToTemp(Video $video): string
    {
        $path = $this->tempPath(pathinfo($video->storage_key, PATHINFO_EXTENSION) ?: 'mp4');
        $out = fopen($path, 'wb');
        $in = $this->readStream($video);

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        return $path;
    }

    public function tempPath(string $extension): string
    {
        $dir = storage_path('app/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        return $dir.'/'.Str::uuid().'.'.$extension;
    }

    public function delete(string $key, ?string $disk = null): void
    {
        $this->disk($disk)->delete($key);
    }

    public function chunkDisk(): Filesystem
    {
        return Storage::disk(config('reelflow.storage.chunk_disk'));
    }
}
