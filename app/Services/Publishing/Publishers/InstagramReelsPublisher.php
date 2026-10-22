<?php

namespace App\Services\Publishing\Publishers;

use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Media\PlatformRequirements;
use App\Services\Platforms\MetaGraphClient;
use App\Services\Publishing\MetaErrorClassifier;
use App\Services\Publishing\PublishContext;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\SocialPublisherInterface;
use App\Services\Publishing\StepResult;
use App\Services\Storage\VideoStorage;
use GuzzleHttp\Psr7\Utils;

/**
 * Instagram Graph API content publishing (Reels):
 * https://developers.facebook.com/docs/instagram-platform/content-publishing
 *
 * create container → (resumable byte upload) → poll status_code → media_publish.
 * The container ID acts as an idempotency key: a container can be published once.
 */
class InstagramReelsPublisher implements SocialPublisherInterface
{
    /** Containers expire 24 hours after creation. */
    private const CONTAINER_TTL_HOURS = 23;

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly VideoStorage $storage,
        private readonly PlatformRequirements $requirements,
    ) {}

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function assertAccountReady(SocialAccount $account): void
    {
        if ($account->account_type !== 'instagram_professional') {
            throw PublishException::make(ErrorCategory::AccountIneligible, 'Only Instagram Professional (Business/Creator) accounts can publish via the API.');
        }
        if ($account->scopes && ! $account->hasScope('instagram_content_publish')) {
            throw PublishException::make(ErrorCategory::PermissionMissing, 'Permission instagram_content_publish was not granted.');
        }
    }

    /**
     * Instagram enforces a rolling 24-hour publishing limit per account.
     */
    public function assertQuotaAvailable(PublishContext $ctx): void
    {
        $data = $this->graph->get($ctx->account->external_id.'/content_publishing_limit', $ctx->accessToken, [
            'fields' => 'quota_usage,config',
        ], 'Instagram publishing limit');

        $row = $data['data'][0] ?? null;
        $used = (int) ($row['quota_usage'] ?? 0);
        $total = (int) ($row['config']['quota_total'] ?? 0);
        if ($total > 0 && $used >= $total) {
            throw new PublishException(ErrorCategory::QuotaExhausted, "Instagram 24-hour publishing limit reached ({$used}/{$total}).", null, ['quota_usage' => $used, 'quota_total' => $total], 3600);
        }
    }

    public function videoProblems(Video $video): array
    {
        return $this->requirements->problems($video, Platform::Instagram);
    }

    public function prepareUpload(PublishContext $ctx): void
    {
        $post = $ctx->post;
        if ($post->external_container_id && $post->publishing_started_at?->gt(now()->subHours(self::CONTAINER_TTL_HOURS))) {
            return;
        }

        $this->assertQuotaAvailable($ctx);

        $params = [
            'media_type' => 'REELS',
            'caption' => $ctx->content->fullCaption(),
            'share_to_feed' => 'true',
        ];

        if (config('reelflow.meta.instagram_upload_mode') === 'video_url') {
            // Instagram downloads the file itself; the signed URL must be publicly reachable.
            $params['video_url'] = $this->storage->temporaryUrl($ctx->video, now()->addHours(3));
        } else {
            $params['upload_type'] = 'resumable';
        }

        $data = $this->graph->post($ctx->account->external_id.'/media', $ctx->accessToken, $params, 'Instagram create container');
        if (empty($data['id'])) {
            throw PublishException::make(ErrorCategory::Unknown, 'Instagram did not return a container ID.');
        }

        $post->checkpoint([
            'external_container_id' => (string) $data['id'],
            'publishing_started_at' => now(),
        ]);
    }

    public function upload(PublishContext $ctx): void
    {
        if (config('reelflow.meta.instagram_upload_mode') === 'video_url') {
            return;
        }

        $local = $this->storage->downloadToTemp($ctx->video);
        try {
            $result = $this->graph->rupload(
                $this->graph->ruploadUrl('ig-api-upload', $ctx->post->external_container_id),
                $ctx->accessToken,
                Utils::streamFor(fopen($local, 'rb')),
                ['offset' => '0', 'file_size' => (string) $ctx->video->size_bytes],
                'Instagram upload',
            );
        } catch (PublishException $e) {
            throw $e->category === ErrorCategory::Network ? $e->withCategory(ErrorCategory::UploadInterrupted) : $e;
        } finally {
            @unlink($local);
        }

        if (($result['success'] ?? false) !== true) {
            throw PublishException::make(ErrorCategory::UploadInterrupted, 'Instagram did not acknowledge the upload.', null, $result);
        }
    }

    public function checkProcessing(PublishContext $ctx): StepResult
    {
        $data = $this->containerStatus($ctx);
        $code = $data['status_code'] ?? null;

        return match ($code) {
            'FINISHED' => StepResult::done(),
            'IN_PROGRESS' => StepResult::pending('Instagram is processing the video.'),
            'PUBLISHED' => StepResult::done(note: 'Container already published.'),
            'EXPIRED' => throw new PublishException(ErrorCategory::MediaProcessing, 'Instagram media container expired before publishing.', null, ['status_code' => $code], null, true),
            'ERROR' => throw $this->containerError((string) ($data['status'] ?? '')),
            default => StepResult::pending('Instagram container status: '.($code ?? 'unknown')),
        };
    }

    public function publish(PublishContext $ctx): void
    {
        $data = $this->graph->post($ctx->account->external_id.'/media_publish', $ctx->accessToken, [
            'creation_id' => $ctx->post->external_container_id,
        ], 'Instagram media_publish');

        if (empty($data['id'])) {
            throw PublishException::make(ErrorCategory::Ambiguous, 'Instagram media_publish returned no media ID.', null, $data);
        }

        $ctx->post->checkpoint(['external_post_id' => (string) $data['id']]);
    }

    public function confirm(PublishContext $ctx): StepResult
    {
        if (! $ctx->post->external_post_id) {
            return StepResult::done(null, null, 'Published (confirmed via container status; media ID unavailable).');
        }

        $data = $this->graph->get($ctx->post->external_post_id, $ctx->accessToken, ['fields' => 'id,permalink,media_product_type'], 'Instagram media lookup');

        return StepResult::done((string) $data['id'], $data['permalink'] ?? null);
    }

    public function reconcile(PublishContext $ctx): StepResult
    {
        if (! $ctx->post->external_container_id) {
            return StepResult::notPublished('No container was created.');
        }

        $code = $this->containerStatus($ctx)['status_code'] ?? null;

        if ($code === 'FINISHED') {
            return StepResult::notPublished('Container is ready but unpublished.');
        }
        if ($code !== 'PUBLISHED') {
            return StepResult::unknown('Instagram container status is "'.($code ?? 'missing').'".');
        }

        // Published: try to find the resulting media for its ID and permalink.
        $recent = $this->graph->get($ctx->account->external_id.'/media', $ctx->accessToken, [
            'fields' => 'id,permalink,timestamp,caption', 'limit' => 15,
        ], 'Instagram recent media');

        $prefix = mb_substr(trim($ctx->content->fullCaption()), 0, 60);
        foreach ($recent['data'] ?? [] as $media) {
            if ($prefix !== '' && str_starts_with(trim((string) ($media['caption'] ?? '')), $prefix)) {
                return StepResult::done((string) $media['id'], $media['permalink'] ?? null);
            }
        }

        return StepResult::done(null, null, 'Instagram reports the container as published; media ID not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function containerStatus(PublishContext $ctx): array
    {
        return $this->graph->get($ctx->post->external_container_id, $ctx->accessToken, ['fields' => 'status_code,status'], 'Instagram container status');
    }

    private function containerError(string $status): PublishException
    {
        // status looks like "Error: ... error code 2207026" when available.
        $subcode = preg_match('/(22\d{5})/', $status, $m) ? (int) $m[1] : 0;
        $category = $subcode ? MetaErrorClassifier::category(400, 9, $subcode, false) : ErrorCategory::MediaProcessing;
        if ($category === ErrorCategory::Unknown) {
            $category = ErrorCategory::MediaProcessing;
        }

        return new PublishException($category, 'Instagram could not process the video: '.($status ?: 'unknown error'), null, ['status' => $status], null, $category === ErrorCategory::MediaProcessing);
    }
}
