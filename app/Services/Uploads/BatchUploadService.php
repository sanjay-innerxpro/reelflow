<?php

namespace App\Services\Uploads;

use App\Jobs\ProcessUploadBatchJob;
use App\Models\UploadBatch;
use App\Models\User;
use App\Services\Storage\VideoStorage;
use App\Services\Sync\PathSanitizer;
use App\Services\Sync\UploadException;
use App\Support\Tenancy\Tenant;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Browser uploads: a ZIP of up to web_uploads.max_videos videos, or a single
 * video, sent in chunks and unpacked on the server.
 */
class BatchUploadService
{
    public function __construct(
        private readonly VideoStorage $storage,
        private readonly VideoIngest $ingest,
    ) {}

    public function create(User $user, string $name, int $size): UploadBatch
    {
        $name = mb_substr(basename(str_replace('\\', '/', $name)), 0, 255);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $kind = $extension === 'zip' ? UploadBatch::KIND_ZIP : UploadBatch::KIND_VIDEO;

        if ($kind === UploadBatch::KIND_VIDEO && ! in_array($extension, config('reelflow.uploads.allowed_extensions'), true)) {
            throw new UploadException('Upload a .zip file or a video ('.implode(', ', config('reelflow.uploads.allowed_extensions')).').', 422, 'invalid_extension');
        }

        $maxMb = config('reelflow.web_uploads.max_mb');
        if ($size <= 0 || $size > $maxMb * 1024 * 1024) {
            throw new UploadException("Uploads can be at most {$maxMb} MB at a time.", 422, 'invalid_size');
        }

        $workspace = Tenant::workspace();
        if ($workspace && ! $workspace->hasStorageFor($size)) {
            throw new UploadException("Not enough storage left (limit {$workspace->storage_limit_mb} MB). Delete videos or ask the administrator for more space.", 422, 'storage_limit');
        }
        if (UploadBatch::whereIn('status', [UploadBatch::UPLOADING, UploadBatch::PROCESSING])->where('expires_at', '>', now())->count() >= config('reelflow.web_uploads.open_per_workspace')) {
            throw new UploadException('Too many uploads in progress. Wait for them to finish.', 429, 'too_many_uploads');
        }

        $chunkSize = config('reelflow.web_uploads.chunk_mb') * 1024 * 1024;

        return UploadBatch::create([
            'user_id' => $user->id,
            'original_name' => $name,
            'kind' => $kind,
            'size_bytes' => $size,
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) max(1, ceil($size / $chunkSize)),
            'status' => UploadBatch::UPLOADING,
            'expires_at' => now()->addHours(config('reelflow.web_uploads.expire_hours')),
        ]);
    }

    /**
     * Store one chunk; re-sending a chunk overwrites it.
     *
     * @param  resource  $body
     */
    public function storeChunk(UploadBatch $batch, int $index, $body): void
    {
        if (! $batch->isOpen()) {
            throw new UploadException('This upload is no longer open.', 409, 'upload_closed');
        }
        if ($index < 0 || $index >= $batch->total_chunks) {
            throw new UploadException('Chunk index out of range.', 422, 'invalid_chunk');
        }

        $expected = $batch->expectedChunkSize($index);
        $tmp = $this->storage->tempPath('part');
        $out = fopen($tmp, 'wb');
        $written = 0;

        try {
            while (! feof($body)) {
                $buffer = fread($body, 1024 * 1024);
                if ($buffer === false || $buffer === '') {
                    break;
                }
                $written += strlen($buffer);
                if ($written > $expected) {
                    throw new UploadException('Chunk is larger than expected.', 422, 'chunk_size_mismatch');
                }
                fwrite($out, $buffer);
            }
            fclose($out);

            if ($written !== $expected) {
                throw new UploadException("Chunk size {$written} does not match expected {$expected}.", 422, 'chunk_size_mismatch');
            }

            $stream = fopen($tmp, 'rb');
            $this->storage->chunkDisk()->writeStream($batch->chunkPath($index), $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
        }
    }

    /**
     * @return list<int> indexes of the chunks stored so far
     */
    public function receivedChunks(UploadBatch $batch): array
    {
        $disk = $this->storage->chunkDisk();
        $received = [];
        for ($i = 0; $i < $batch->total_chunks; $i++) {
            if ($disk->exists($batch->chunkPath($i)) && $disk->size($batch->chunkPath($i)) === $batch->expectedChunkSize($i)) {
                $received[] = $i;
            }
        }

        return $received;
    }

    public function complete(UploadBatch $batch): UploadBatch
    {
        if ($batch->status !== UploadBatch::UPLOADING) {
            return $batch;
        }
        if (! $batch->isOpen()) {
            throw new UploadException('This upload has expired. Start it again.', 409, 'upload_closed');
        }

        $missing = $batch->total_chunks - count($this->receivedChunks($batch));
        if ($missing > 0) {
            throw new UploadException("Upload incomplete: {$missing} chunk(s) missing.", 409, 'upload_incomplete');
        }

        $claimed = UploadBatch::whereKey($batch->id)->where('status', UploadBatch::UPLOADING)
            ->update(['status' => UploadBatch::PROCESSING, 'updated_at' => now()]);
        if ($claimed === 1) {
            ProcessUploadBatchJob::dispatch($batch->id);
        }

        return $batch->refresh();
    }

    /**
     * Assemble the chunks and add the video(s). Runs in the queue, inside the
     * batch's workspace.
     */
    public function process(UploadBatch $batch): void
    {
        if ($batch->status !== UploadBatch::PROCESSING) {
            return;
        }

        $file = $this->storage->tempPath($batch->kind === UploadBatch::KIND_ZIP ? 'zip' : 'upload');

        try {
            $this->assemble($batch, $file);
            $folder = 'Uploads/'.PathSanitizer::label(pathinfo($batch->original_name, PATHINFO_FILENAME));
            $results = $batch->kind === UploadBatch::KIND_ZIP
                ? $this->unzip($file, $folder)
                : [$this->row($batch->original_name, $this->ingest->store($file, $batch->original_name, $folder, 'browser upload'))];

            $batch->update([
                'status' => UploadBatch::COMPLETED,
                'results' => $results,
                'added_count' => collect($results)->where('status', IngestResult::ADDED)->count(),
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $batch->update(['status' => UploadBatch::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'completed_at' => now()]);
        } finally {
            @unlink($file);
            $this->storage->chunkDisk()->deleteDirectory($batch->chunkDirectory());
        }
    }

    public function discardChunks(UploadBatch $batch): void
    {
        $this->storage->chunkDisk()->deleteDirectory($batch->chunkDirectory());
    }

    private function assemble(UploadBatch $batch, string $target): void
    {
        $out = fopen($target, 'wb');
        try {
            for ($i = 0; $i < $batch->total_chunks; $i++) {
                $in = $this->storage->chunkDisk()->readStream($batch->chunkPath($i));
                if (! is_resource($in)) {
                    throw new RuntimeException("Part {$i} of the upload is missing. Upload the file again.");
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        if (filesize($target) !== $batch->size_bytes) {
            throw new RuntimeException('The uploaded file is incomplete. Upload it again.');
        }
    }

    /**
     * @return list<array{name: string, status: string, message: ?string, video_id: ?int}>
     */
    private function unzip(string $path, string $folder): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This is not a valid ZIP file.');
        }

        try {
            $allowed = config('reelflow.uploads.allowed_extensions');
            $videos = [];
            $results = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = (string) ($entry['name'] ?? '');
                $base = basename(str_replace('\\', '/', $name));
                // Folders, macOS metadata and hidden files are not content.
                if ($name === '' || str_ends_with($name, '/') || str_contains($name, '__MACOSX/') || str_starts_with($base, '.')) {
                    continue;
                }
                if (! in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), $allowed, true)) {
                    $results[] = ['name' => $base, 'status' => IngestResult::SKIPPED, 'message' => 'Not a video file.', 'video_id' => null];

                    continue;
                }
                $videos[] = $entry + ['base' => $base, 'index' => $i];
            }

            $max = config('reelflow.web_uploads.max_videos');
            if ($videos === []) {
                throw new RuntimeException('No videos were found in the ZIP ('.implode(', ', $allowed).').');
            }
            if (count($videos) > $max) {
                throw new RuntimeException('The ZIP contains '.count($videos)." videos; the limit is {$max} per upload. Split it into smaller ZIPs.");
            }
            $unpacked = array_sum(array_column($videos, 'size'));
            if ($unpacked > config('reelflow.web_uploads.max_unpacked_mb') * 1024 * 1024) {
                throw new RuntimeException('The ZIP unpacks to more than '.config('reelflow.web_uploads.max_unpacked_mb').' MB.');
            }

            foreach ($videos as $entry) {
                $results[] = $this->unzipEntry($zip, $entry, $folder);
            }

            return $results;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, mixed>  $entry  ZipArchive::statIndex() plus base/index
     * @return array{name: string, status: string, message: ?string, video_id: ?int}
     */
    private function unzipEntry(ZipArchive $zip, array $entry, string $folder): array
    {
        $name = $entry['base'];
        if (($entry['encryption_method'] ?? 0) !== 0) {
            return ['name' => $name, 'status' => IngestResult::SKIPPED, 'message' => 'Password-protected files are not supported.', 'video_id' => null];
        }
        // Video barely compresses; a huge ratio means a crafted "zip bomb".
        if ($entry['comp_size'] > 0 && config('reelflow.web_uploads.max_compression_ratio') < $entry['size'] / $entry['comp_size']) {
            return ['name' => $name, 'status' => IngestResult::SKIPPED, 'message' => 'Suspicious compression; not unpacked.', 'video_id' => null];
        }

        $tmp = $this->storage->tempPath('entry');
        try {
            $in = $zip->getStreamIndex($entry['index']);
            if (! is_resource($in)) {
                return ['name' => $name, 'status' => IngestResult::SKIPPED, 'message' => 'Could not read this file from the ZIP.', 'video_id' => null];
            }
            $out = fopen($tmp, 'wb');
            // Never write more than the size the ZIP declares.
            $copied = stream_copy_to_stream($in, $out, $entry['size'] + 1);
            fclose($in);
            fclose($out);
            if ($copied !== $entry['size']) {
                return ['name' => $name, 'status' => IngestResult::SKIPPED, 'message' => 'The file inside the ZIP is damaged.', 'video_id' => null];
            }

            return $this->row($name, $this->ingest->store($tmp, $name, $folder, 'ZIP upload'));
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{name: string, status: string, message: ?string, video_id: ?int}
     */
    private function row(string $name, IngestResult $result): array
    {
        return ['name' => $name, 'status' => $result->status, 'message' => $result->message, 'video_id' => $result->video?->id];
    }
}
