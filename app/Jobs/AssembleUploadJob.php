<?php

namespace App\Jobs;

use App\Enums\UploadSessionStatus;
use App\Enums\VideoStatus;
use App\Models\UploadSession;
use App\Services\Storage\VideoStorage;
use App\Services\Sync\UploadService;
use App\Support\ActivityLogger;
use App\Support\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssembleUploadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $uploadSessionId)
    {
        $this->onConnection(config('queue.long_connection'));
        $this->onQueue('ingest');
    }

    public function handle(VideoStorage $storage, UploadService $uploads, ActivityLogger $activity): void
    {
        $session = UploadSession::allWorkspaces()->find($this->uploadSessionId);
        if (! $session || $session->status !== UploadSessionStatus::Assembling) {
            return;
        }

        Tenant::run($session->workspace_id, fn () => $this->assemble($session->load('video'), $storage, $uploads, $activity));
    }

    private function assemble(UploadSession $session, VideoStorage $storage, UploadService $uploads, ActivityLogger $activity): void
    {
        $video = $session->video;
        $tmp = $storage->tempPath('assemble');
        $out = fopen($tmp, 'wb');
        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            for ($i = 0; $i < $session->total_chunks; $i++) {
                $in = $storage->chunkDisk()->readStream($session->chunkPath($i));
                if (! is_resource($in)) {
                    throw new \RuntimeException("Chunk {$i} is missing.");
                }
                while (! feof($in)) {
                    $buffer = fread($in, 1024 * 1024);
                    if ($buffer === false) {
                        break;
                    }
                    hash_update($hash, $buffer);
                    $bytes += fwrite($out, $buffer);
                }
                fclose($in);
            }
            fclose($out);

            $checksum = hash_final($hash);
            if ($bytes !== $video->size_bytes || ! hash_equals($video->checksum_sha256, $checksum)) {
                $session->update(['status' => UploadSessionStatus::Failed, 'error' => 'Checksum or size mismatch after assembly.']);
                $uploads->recordVideoFailure($video, 'Uploaded file did not match the registered checksum. The agent will re-upload it.');
                $uploads->deleteChunks($session);

                return;
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
            if (! str_starts_with($mime, 'video/') && $mime !== 'application/octet-stream') {
                $session->update(['status' => UploadSessionStatus::Failed, 'error' => "Not a video file ({$mime})."]);
                $uploads->recordVideoFailure($video, "The uploaded file is not a video ({$mime}).");
                $uploads->deleteChunks($session);

                return;
            }

            $extension = strtolower(pathinfo($video->original_filename, PATHINFO_EXTENSION)) ?: 'mp4';
            $key = $storage->keyFor($video->workspace_id, $video->checksum_sha256, $extension);
            $stream = fopen($tmp, 'rb');
            $storage->putStream($key, $stream, null, $mime);
            if (is_resource($stream)) {
                fclose($stream);
            }

            DB::transaction(function () use ($session, $video, $storage, $key, $mime) {
                $video->transitionTo(VideoStatus::Uploaded, [
                    'storage_disk' => $storage->defaultDisk(),
                    'storage_key' => $key,
                    'mime_type' => $mime,
                    'uploaded_at' => now(),
                    'processing_error' => null,
                ], 'Upload assembled and stored');
                $session->update(['status' => UploadSessionStatus::Completed, 'completed_at' => now()]);
            });

            $uploads->deleteChunks($session);
            $activity->log('video.uploaded', $video, 'Stored in '.$storage->defaultDisk());

            ProcessVideoJob::dispatch($video->id);
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
        }
    }

    public function failed(Throwable $e): void
    {
        $session = UploadSession::allWorkspaces()->find($this->uploadSessionId);
        if (! $session) {
            return;
        }
        Tenant::run($session->workspace_id, function () use ($session, $e) {
            $session->update(['status' => UploadSessionStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            app(UploadService::class)->recordVideoFailure($session->load('video')->video, 'Assembling the upload failed: '.$e->getMessage());
        });
    }
}
