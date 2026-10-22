<?php

namespace App\Services\Publishing\Publishers;

use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Media\PlatformRequirements;
use App\Services\Platforms\GoogleClient;
use App\Services\Publishing\PublishContext;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\SocialPublisherInterface;
use App\Services\Publishing\StepResult;
use App\Services\Storage\VideoStorage;
use Illuminate\Support\Facades\Http;

/**
 * YouTube Data API v3 resumable upload:
 * https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol
 *
 * The session URI is the idempotency key: after an interrupted transfer we ask
 * the session how many bytes it holds (or whether it already created the
 * video) instead of starting a second upload.
 *
 * Whether YouTube classifies an upload as a Short depends on its format
 * (vertical/square and within the Shorts length limit); it is not guaranteed.
 */
class YouTubeShortsPublisher implements SocialPublisherInterface
{
    public function __construct(
        private readonly GoogleClient $google,
        private readonly VideoStorage $storage,
        private readonly PlatformRequirements $requirements,
    ) {}

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    public function assertAccountReady(SocialAccount $account): void
    {
        if ($account->scopes && ! $account->hasScope('https://www.googleapis.com/auth/youtube.upload')
            && ! $account->hasScope('https://www.googleapis.com/auth/youtube')) {
            throw PublishException::make(ErrorCategory::PermissionMissing, 'The youtube.upload permission was not granted.');
        }
    }

    public function videoProblems(Video $video): array
    {
        return $this->requirements->problems($video, Platform::YouTube);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(PublishContext $ctx): array
    {
        $rule = $ctx->account->postingRule;
        $privacy = $rule?->privacy_status ?: 'public';
        $status = ['privacyStatus' => $privacy, 'selfDeclaredMadeForKids' => false];

        // Upload ahead of time and let YouTube publish at the slot.
        if ($ctx->post->scheduled_at->gt(now()->addMinutes(15)) && $privacy === 'public') {
            $status['privacyStatus'] = 'private';
            $status['publishAt'] = $ctx->post->scheduled_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $snippet = [
            'title' => $ctx->content->title ?: pathinfo($ctx->video->original_filename, PATHINFO_FILENAME),
            'description' => $ctx->content->fullDescription(),
            'tags' => array_values($ctx->content->tags ?? []),
            'categoryId' => (string) config('reelflow.google.default_category_id'),
        ];
        if ($ctx->content->language && preg_match('/^[a-z]{2}$/', $ctx->content->language)) {
            $snippet['defaultLanguage'] = $ctx->content->language;
        }

        return ['snippet' => $snippet, 'status' => $status];
    }

    public function prepareUpload(PublishContext $ctx): void
    {
        if ($ctx->post->upload_session_url) {
            return;
        }

        $url = rtrim(config('reelflow.google.upload_url'), '/').'/videos?uploadType=resumable&part=snippet,status';
        $response = $this->google->call(fn () => Http::withToken($ctx->accessToken)
            ->timeout(60)
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $ctx->video->size_bytes,
                'X-Upload-Content-Type' => $ctx->video->mime_type ?: 'video/*',
            ])
            ->post($url, $this->metadata($ctx)), 'YouTube upload init');

        $location = $response->header('Location');
        if (! $location) {
            throw PublishException::make(ErrorCategory::Unknown, 'YouTube did not return an upload session URL.');
        }

        $ctx->post->checkpoint(['upload_session_url' => $location]);
    }

    public function upload(PublishContext $ctx): void
    {
        if ($ctx->post->external_post_id) {
            return;
        }

        $total = $ctx->video->size_bytes;
        $offset = $this->committedBytes($ctx);
        if ($offset === null) {
            return; // session already completed; video ID recorded
        }

        $chunk = max(1, intdiv(config('reelflow.publishing.upload_chunk_mb') * 1024 * 1024, 262144)) * 262144;
        $local = $this->storage->downloadToTemp($ctx->video);
        $handle = fopen($local, 'rb');

        try {
            while ($offset < $total) {
                fseek($handle, $offset);
                $bytes = (string) fread($handle, min($chunk, $total - $offset));
                $end = $offset + strlen($bytes) - 1;

                try {
                    $response = $this->google->call(fn () => Http::withToken($ctx->accessToken)
                        ->timeout(config('reelflow.publishing.http_timeout_seconds'))
                        ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$total}"])
                        ->withBody($bytes, $ctx->video->mime_type ?: 'application/octet-stream')
                        ->put($ctx->post->upload_session_url), 'YouTube upload chunk');
                } catch (PublishException $e) {
                    // Next attempt asks the session for its state, so this is safely resumable.
                    throw in_array($e->category, [ErrorCategory::Network, ErrorCategory::UploadInterrupted], true)
                        ? new PublishException(ErrorCategory::UploadInterrupted, $e->getMessage(), $e->httpStatus, $e->responseMeta, null, $e->httpStatus === 404 || $e->httpStatus === 410, $e)
                        : $e;
                }

                if ($response->status() === 308) {
                    $offset = $this->rangeEnd($response->header('Range')) + 1;

                    continue;
                }

                $this->recordVideo($ctx, (array) $response->json());

                return;
            }
        } finally {
            fclose($handle);
            @unlink($local);
        }
    }

    public function checkProcessing(PublishContext $ctx): StepResult
    {
        // YouTube creates the video when the upload completes; its processing
        // is tracked in confirm().
        return $ctx->post->external_post_id ? StepResult::done() : StepResult::pending('Waiting for upload to complete.');
    }

    public function publish(PublishContext $ctx): void
    {
        // Visibility was set at upload time (privacyStatus / publishAt).
    }

    public function confirm(PublishContext $ctx): StepResult
    {
        $data = $this->google->get('videos', $ctx->accessToken, [
            'part' => 'status,processingDetails', 'id' => $ctx->post->external_post_id,
        ], 'YouTube video status');

        $item = $data['items'][0] ?? null;
        if (! $item) {
            return StepResult::pending('YouTube has not listed the video yet.');
        }

        $status = $item['status'] ?? [];
        $url = 'https://www.youtube.com/shorts/'.$ctx->post->external_post_id;

        return match ($status['uploadStatus'] ?? null) {
            'processed' => StepResult::done($ctx->post->external_post_id, $url),
            'uploaded' => StepResult::pending('YouTube is processing the video.'),
            'rejected' => throw PublishException::make(ErrorCategory::InvalidVideo, 'YouTube rejected the video: '.($status['rejectionReason'] ?? 'unknown reason'), null, ['status' => $status]),
            'failed' => throw PublishException::make(ErrorCategory::MediaProcessing, 'YouTube processing failed: '.($status['failureReason'] ?? 'unknown reason'), null, ['status' => $status]),
            'deleted' => throw PublishException::make(ErrorCategory::Unknown, 'The video was deleted on YouTube.'),
            default => StepResult::pending('YouTube upload status: '.($status['uploadStatus'] ?? 'unknown')),
        };
    }

    public function reconcile(PublishContext $ctx): StepResult
    {
        return $ctx->post->external_post_id
            ? StepResult::done($ctx->post->external_post_id)
            : StepResult::notPublished('No YouTube video was created.');
    }

    /**
     * Ask the session how far it got. Returns the next byte offset, or null
     * if the session already finished (and records the video ID).
     */
    private function committedBytes(PublishContext $ctx): ?int
    {
        try {
            $response = $this->google->call(fn () => Http::withToken($ctx->accessToken)
                ->timeout(60)
                ->withHeaders(['Content-Range' => 'bytes */'.$ctx->video->size_bytes])
                ->withBody('', 'application/octet-stream')
                ->put($ctx->post->upload_session_url), 'YouTube upload status');
        } catch (PublishException $e) {
            if ($e->httpStatus === 404 || $e->httpStatus === 410) {
                // Session expired without completing; start a fresh one.
                $ctx->post->checkpoint(['upload_session_url' => null]);
                throw new PublishException(ErrorCategory::UploadInterrupted, 'YouTube upload session expired; restarting upload.', $e->httpStatus, [], null, true, $e);
            }
            throw $e;
        }

        if ($response->status() === 308) {
            $range = $response->header('Range');

            return $range ? $this->rangeEnd($range) + 1 : 0;
        }

        $this->recordVideo($ctx, (array) $response->json());

        return null;
    }

    /**
     * @param  array<string, mixed>  $video
     */
    private function recordVideo(PublishContext $ctx, array $video): void
    {
        if (empty($video['id'])) {
            throw PublishException::make(ErrorCategory::Ambiguous, 'YouTube finished the upload but returned no video ID.');
        }

        $ctx->post->checkpoint([
            'external_post_id' => (string) $video['id'],
            'post_url' => 'https://www.youtube.com/shorts/'.$video['id'],
        ]);
    }

    private function rangeEnd(string $range): int
    {
        // "bytes=0-524287"
        return preg_match('/bytes=\d+-(\d+)/', $range, $m) ? (int) $m[1] : -1;
    }
}
