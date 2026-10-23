<?php

namespace App\Services\Drive;

use App\Enums\VideoStatus;
use App\Jobs\ProcessVideoJob;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Video;
use App\Models\Workspace;
use App\Services\Storage\VideoStorage;
use App\Services\Sync\PathSanitizer;
use App\Services\Sync\UploadService;
use App\Support\ActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Server-side import from Google Drive, run inside the folder's workspace. Same outcome as an agent upload:
 * a stored, de-duplicated video (by SHA-256) queued for processing.
 */
class DriveImporter
{
    /** Returned (not stored) when the workspace's storage limit is reached; the file stays pending. */
    public const QUOTA = 'quota';

    public function __construct(
        private readonly GoogleDriveClient $drive,
        private readonly VideoStorage $storage,
        private readonly ActivityLogger $activity,
        private readonly UploadService $uploads,
    ) {}

    /**
     * Refresh the file list of a folder. New files become pending; files that
     * are not videos are skipped. Returns the number of new files.
     */
    public function refresh(DriveFolder $folder): int
    {
        try {
            $files = $this->drive->listFolder($folder->folder_id);
        } catch (Throwable $e) {
            $folder->update(['last_listed_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }

        $known = $folder->files()->pluck('file_id')->flip();
        $extensions = config('reelflow.uploads.allowed_extensions');
        $new = 0;

        foreach ($files as $file) {
            if ($known->has($file['id'])) {
                continue;
            }
            $allowed = in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), $extensions, true);
            $folder->files()->create([
                'file_id' => $file['id'],
                'name' => mb_substr($file['name'], 0, 255),
                'size_bytes' => $file['size'],
                'status' => $allowed ? DriveFile::PENDING : DriveFile::SKIPPED,
                'error' => $allowed ? null : 'Not an allowed video type ('.implode(', ', $extensions).').',
            ]);
            $new++;
        }

        $folder->update(['last_listed_at' => now(), 'last_error' => null]);

        return $new;
    }

    /**
     * Download one file into video storage. Never throws: failures are
     * recorded on the row and retried up to drive.max_attempts times.
     */
    public function import(DriveFile $file): string
    {
        $workspace = Workspace::findOrFail($file->workspace_id);
        if ($file->size_bytes !== null && ! $workspace->hasStorageFor($file->size_bytes)) {
            return $this->overQuota($file, $workspace);
        }

        $file->increment('attempts');
        $tmp = $this->storage->tempPath('download');

        try {
            $maxBytes = config('reelflow.uploads.max_file_size_mb') * 1024 * 1024;
            if ($file->size_bytes !== null && $file->size_bytes > $maxBytes) {
                return $this->finish($file, DriveFile::SKIPPED, error: 'Larger than UPLOAD_MAX_FILE_SIZE_MB.');
            }

            $this->drive->download($file->file_id, $tmp);

            $size = (int) filesize($tmp);
            if ($size <= 0 || $size > $maxBytes) {
                return $this->finish($file, DriveFile::SKIPPED, error: $size <= 0 ? 'The downloaded file is empty.' : 'Larger than UPLOAD_MAX_FILE_SIZE_MB.');
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
            if (! str_starts_with($mime, 'video/') && $mime !== 'application/octet-stream') {
                return $this->finish($file, DriveFile::SKIPPED, error: "Not a video file ({$mime}).");
            }

            $checksum = hash_file('sha256', $tmp);
            $existing = Video::where('checksum_sha256', $checksum)->first();
            if ($existing && ! $this->uploads->needsUpload($existing)) {
                return $this->finish($file, DriveFile::DUPLICATE, $existing);
            }
            if (! $workspace->hasStorageFor($size)) {
                $file->decrement('attempts');

                return $this->overQuota($file, $workspace);
            }

            $extension = strtolower(pathinfo($file->name, PATHINFO_EXTENSION)) ?: 'mp4';
            $key = $this->storage->keyFor($workspace->id, $checksum, $extension);
            $stream = fopen($tmp, 'rb');
            try {
                $this->storage->putStream($key, $stream, null, $mime);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($existing) {
                // Registered by the sync agent but its upload never finished:
                // complete it with the Drive copy instead of adding a duplicate.
                $existing->transitionTo(VideoStatus::Uploaded, [
                    'storage_disk' => $this->storage->defaultDisk(),
                    'storage_key' => $key,
                    'mime_type' => $mime,
                    'uploaded_at' => now(),
                    'processing_error' => null,
                ], 'Completed from Google Drive');
                ProcessVideoJob::dispatch($existing->id);

                return $this->finish($file, DriveFile::IMPORTED, $existing);
            }

            try {
                $video = Video::create([
                    'original_filename' => mb_substr(basename($file->name), 0, 255),
                    'folder' => PathSanitizer::label($file->folder->name),
                    'checksum_sha256' => $checksum,
                    'size_bytes' => $size,
                    'storage_disk' => $this->storage->defaultDisk(),
                    'storage_key' => $key,
                    'mime_type' => $mime,
                    'status' => VideoStatus::Uploaded,
                    'uploaded_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Same file imported concurrently (e.g. by the sync agent).
                return $this->finish($file, DriveFile::DUPLICATE, Video::where('checksum_sha256', $checksum)->first());
            }

            $this->activity->log('video.imported', $video, "Imported from Google Drive folder {$file->folder->name}");
            ProcessVideoJob::dispatch($video->id);

            return $this->finish($file, DriveFile::IMPORTED, $video);
        } catch (Throwable $e) {
            $final = $file->attempts >= config('reelflow.drive.max_attempts');

            return $this->finish($file, $final ? DriveFile::FAILED : DriveFile::PENDING, error: mb_substr($e->getMessage(), 0, 2000));
        } finally {
            @unlink($tmp);
        }
    }

    private function overQuota(DriveFile $file, Workspace $workspace): string
    {
        $file->update(['error' => "Storage limit of {$workspace->storage_limit_mb} MB reached. Free space (delete videos) or ask the administrator for more."]);

        return self::QUOTA;
    }

    private function finish(DriveFile $file, string $status, ?Video $video = null, ?string $error = null): string
    {
        $file->update([
            'status' => $status,
            'video_id' => $video?->id ?? $file->video_id,
            'error' => $error,
            'imported_at' => in_array($status, [DriveFile::IMPORTED, DriveFile::DUPLICATE], true) ? now() : $file->imported_at,
        ]);

        return $status;
    }
}
