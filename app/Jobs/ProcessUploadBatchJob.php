<?php

namespace App\Jobs;

use App\Models\UploadBatch;
use App\Services\Uploads\BatchUploadService;
use App\Support\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Unpacks a finished browser upload. Re-running is safe: videos already
 * added are recognised by checksum.
 */
class ProcessUploadBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    /** @var array<int, int> */
    public array $backoff = [60];

    public function __construct(public readonly string $batchId)
    {
        $this->onConnection(config('queue.long_connection'));
        $this->onQueue('ingest');
    }

    public function handle(BatchUploadService $uploads): void
    {
        $batch = UploadBatch::allWorkspaces()->find($this->batchId);
        if ($batch) {
            Tenant::run($batch->workspace_id, fn () => $uploads->process($batch));
        }
    }

    public function failed(Throwable $e): void
    {
        UploadBatch::allWorkspaces()->whereKey($this->batchId)->where('status', UploadBatch::PROCESSING)
            ->update(['status' => UploadBatch::FAILED, 'error' => mb_substr('Processing failed: '.$e->getMessage(), 0, 2000), 'completed_at' => now()]);
    }
}
