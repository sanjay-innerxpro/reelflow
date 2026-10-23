<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Media\VideoProcessor;
use App\Support\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $videoId)
    {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function handle(VideoProcessor $processor): void
    {
        $video = Video::allWorkspaces()->find($this->videoId);
        if (! $video || ! in_array($video->status, [VideoStatus::Uploaded, VideoStatus::Processing, VideoStatus::Failed, VideoStatus::Ready], true)) {
            return;
        }
        if ($video->storage_key === null) {
            return;
        }

        Tenant::run($video->workspace_id, fn () => $processor->process($video));
    }

    public function failed(Throwable $e): void
    {
        $video = Video::allWorkspaces()->find($this->videoId);
        if ($video && $video->status->canTransitionTo(VideoStatus::Failed)) {
            Tenant::run($video->workspace_id, fn () => $video->transitionTo(VideoStatus::Failed, ['processing_error' => mb_substr('Processing error: '.$e->getMessage(), 0, 2000)]));
        }
    }
}
