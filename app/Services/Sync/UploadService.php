<?php

namespace App\Services\Sync;

use App\Enums\UploadSessionStatus;
use App\Enums\VideoStatus;
use App\Jobs\AssembleUploadJob;
use App\Models\SyncDevice;
use App\Models\UploadChunk;
use App\Models\UploadSession;
use App\Models\Video;
use App\Models\VideoSource;
use App\Services\Notifications\NotificationService;
use App\Services\Storage\VideoStorage;
use App\Support\ActivityLogger;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

class UploadService
{
    public function __construct(
        private readonly VideoStorage $storage,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Register a local file. Idempotent: the same checksum always maps to the
     * same video, and an unfinished upload session is resumed, not duplicated.
     *
     * @param  array{filename: string, folder_label: string, relative_path: string, size: int, checksum_sha256: string}  $data
     * @return array{video: Video, duplicate: bool, session: ?UploadSession}
     */
    public function register(SyncDevice $device, array $data): array
    {
        $checksum = strtolower($data['checksum_sha256']);
        $relative = PathSanitizer::relative($data['relative_path']);
        if ($relative === null) {
            throw new UploadException('relative_path must be a relative path inside a watched folder.', 422, 'invalid_path');
        }

        $extension = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION));
        if (! in_array($extension, config('reelflow.uploads.allowed_extensions'), true)) {
            throw new UploadException("File extension .{$extension} is not allowed.", 422, 'invalid_extension');
        }

        $maxBytes = config('reelflow.uploads.max_file_size_mb') * 1024 * 1024;
        if ($data['size'] <= 0 || $data['size'] > $maxBytes) {
            throw new UploadException('File size is outside the allowed range.', 422, 'invalid_size');
        }

        return DB::transaction(function () use ($device, $data, $checksum, $relative) {
            $video = Video::where('checksum_sha256', $checksum)->lockForUpdate()->first();
            $duplicate = $video !== null;

            if ($video && $video->size_bytes !== (int) $data['size']) {
                throw new UploadException('A file with this checksum is already registered with a different size.', 409, 'checksum_conflict');
            }

            if (! $video) {
                $workspace = Tenant::workspace();
                if ($workspace && ! $workspace->hasStorageFor((int) $data['size'])) {
                    throw new UploadException("Storage limit of {$workspace->storage_limit_mb} MB reached. Delete videos or ask the administrator for more space.", 422, 'storage_limit');
                }

                $video = Video::create([
                    'user_id' => $device->user_id,
                    'sync_device_id' => $device->id,
                    'original_filename' => mb_substr(basename($data['filename']), 0, 255),
                    'folder' => PathSanitizer::folderFor($data['folder_label'], $relative),
                    'checksum_sha256' => $checksum,
                    'size_bytes' => (int) $data['size'],
                    'status' => VideoStatus::Discovered,
                ]);
                $this->activity->log('video.discovered', $video, "Registered by device {$device->name}");
            }

            VideoSource::updateOrCreate(
                ['sync_device_id' => $device->id, 'path_hash' => hash('sha256', PathSanitizer::label($data['folder_label']).'/'.$relative)],
                [
                    'video_id' => $video->id,
                    'folder_label' => PathSanitizer::label($data['folder_label']),
                    'relative_path' => $relative,
                    'last_seen_at' => now(),
                ],
            );

            if (! $this->needsUpload($video)) {
                return ['video' => $video, 'duplicate' => $duplicate, 'session' => null];
            }

            $session = $this->openSessionFor($video, $device);

            return ['video' => $video->refresh(), 'duplicate' => $duplicate, 'session' => $session];
        });
    }

    public function needsUpload(Video $video): bool
    {
        return in_array($video->status, [VideoStatus::Discovered, VideoStatus::Uploading, VideoStatus::Failed], true)
            && $video->uploaded_at === null;
    }

    private function openSessionFor(Video $video, SyncDevice $device): UploadSession
    {
        $existing = $video->uploadSessions()
            ->where('sync_device_id', $device->id)
            ->whereIn('status', [UploadSessionStatus::Open->value, UploadSessionStatus::Assembling->value])
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $chunkSize = config('reelflow.uploads.chunk_size_mb') * 1024 * 1024;

        $session = UploadSession::create([
            'video_id' => $video->id,
            'sync_device_id' => $device->id,
            'total_size' => $video->size_bytes,
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) max(1, ceil($video->size_bytes / $chunkSize)),
            'status' => UploadSessionStatus::Open,
            'expires_at' => now()->addHours(config('reelflow.uploads.session_ttl_hours')),
        ]);

        if ($video->status !== VideoStatus::Uploading) {
            $video->transitionTo(VideoStatus::Uploading, ['processing_error' => null]);
        }

        return $session;
    }

    /**
     * Store one chunk. Re-sending a chunk is safe (it is overwritten).
     *
     * @param  resource  $body
     */
    public function storeChunk(UploadSession $session, int $index, $body, string $expectedSha256): UploadChunk
    {
        if (! $session->isOpen()) {
            throw new UploadException('Upload session is not open.', 409, 'session_closed');
        }
        if ($index < 0 || $index >= $session->total_chunks) {
            throw new UploadException('Chunk index out of range.', 422, 'invalid_chunk');
        }

        $expectedSize = $session->expectedChunkSize($index);
        $tmp = $this->storage->tempPath('part');
        $out = fopen($tmp, 'wb');
        $hash = hash_init('sha256');
        $written = 0;

        try {
            while (! feof($body)) {
                $buffer = fread($body, 1024 * 1024);
                if ($buffer === false) {
                    break;
                }
                $written += strlen($buffer);
                if ($written > $expectedSize) {
                    throw new UploadException('Chunk is larger than expected.', 422, 'chunk_size_mismatch');
                }
                hash_update($hash, $buffer);
                fwrite($out, $buffer);
            }
            fclose($out);

            if ($written !== $expectedSize) {
                throw new UploadException("Chunk size {$written} does not match expected {$expectedSize}.", 422, 'chunk_size_mismatch');
            }

            $actual = hash_final($hash);
            if (! hash_equals(strtolower($expectedSha256), $actual)) {
                throw new UploadException('Chunk checksum mismatch.', 422, 'chunk_checksum_mismatch');
            }

            $stream = fopen($tmp, 'rb');
            $this->storage->chunkDisk()->writeStream($session->chunkPath($index), $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
        }

        return UploadChunk::updateOrCreate(
            ['upload_session_id' => $session->id, 'chunk_index' => $index],
            ['size' => $written, 'sha256' => $actual],
        );
    }

    /**
     * Mark the session complete and queue assembly. Safe to call repeatedly.
     */
    public function complete(UploadSession $session): UploadSession
    {
        if ($session->status === UploadSessionStatus::Assembling || $session->status === UploadSessionStatus::Completed) {
            return $session;
        }
        if (! $session->isOpen()) {
            throw new UploadException('Upload session is not open.', 409, 'session_closed');
        }

        $received = $session->chunks()->count();
        if ($received !== $session->total_chunks) {
            throw new UploadException("Upload incomplete: {$received} of {$session->total_chunks} chunks received.", 409, 'upload_incomplete');
        }

        $claimed = UploadSession::whereKey($session->id)
            ->where('status', UploadSessionStatus::Open->value)
            ->update(['status' => UploadSessionStatus::Assembling->value, 'updated_at' => now()]);

        if ($claimed === 1) {
            AssembleUploadJob::dispatch($session->id);
        }

        return $session->refresh();
    }

    public function reportFailure(UploadSession $session, string $error): void
    {
        $session->update(['status' => UploadSessionStatus::Failed, 'error' => mb_substr($error, 0, 2000)]);
        $this->recordVideoFailure($session->video, 'Agent reported: '.$error);
        $this->deleteChunks($session);
    }

    public function recordVideoFailure(Video $video, string $error): void
    {
        $video->increment('upload_failures');
        if ($video->status->canTransitionTo(VideoStatus::Failed)) {
            $video->transitionTo(VideoStatus::Failed, ['processing_error' => mb_substr($error, 0, 2000)], $error);
        }

        $threshold = config('reelflow.uploads.repeated_failure_threshold');
        if ($video->upload_failures === $threshold) {
            app(NotificationService::class)->repeatedUploadFailures($video);
        }
    }

    public function deleteChunks(UploadSession $session): void
    {
        $this->storage->chunkDisk()->deleteDirectory($session->chunkDirectory());
        $session->chunks()->delete();
    }
}
