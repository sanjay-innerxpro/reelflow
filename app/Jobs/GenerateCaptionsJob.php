<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\Ai\AiCaptionService;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\InvalidAiResponse;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class GenerateCaptionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public string $generationId;

    public function __construct(public readonly int $videoId, ?string $generationId = null)
    {
        // Fixed for the lifetime of the job so retries reuse the same generation.
        $this->generationId = $generationId ?? (string) Str::uuid();
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60 + random_int(0, 30), 300 + random_int(0, 60)];
    }

    public function handle(AiCaptionService $service): void
    {
        $video = Video::find($this->videoId);
        if (! $video || ! $video->hasStoredFile()) {
            return;
        }

        try {
            $service->generate($video, $this->generationId);
        } catch (InvalidAiResponse $e) {
            $final = $this->attempts() >= $this->tries;
            $service->markFailed($video, $this->generationId, $e->getMessage().' '.json_encode($e->errors), $final);
            if (! $final) {
                throw $e;
            }
        } catch (AiProviderException $e) {
            $final = ! $e->retryable || $this->attempts() >= $this->tries;
            $service->markFailed($video, $this->generationId, $e->getMessage(), $final);
            if (! $final) {
                throw $e;
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $video = Video::find($this->videoId);
        if ($video) {
            app(AiCaptionService::class)->markFailed($video, $this->generationId, $e->getMessage(), true);
        }
    }
}
