<?php

namespace App\Services\Publishing\Publishers;

use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Media\PlatformRequirements;
use App\Services\Platforms\MetaGraphClient;
use App\Services\Publishing\PublishContext;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\SocialPublisherInterface;
use App\Services\Publishing\StepResult;
use App\Services\Storage\VideoStorage;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;

/**
 * Facebook Page Reels publishing:
 * https://developers.facebook.com/docs/video-api/guides/reels-publishing
 *
 * start (video_id) → binary upload to rupload → finish(video_state=PUBLISHED) → poll status.
 */
class FacebookReelsPublisher implements SocialPublisherInterface
{
    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly VideoStorage $storage,
        private readonly PlatformRequirements $requirements,
    ) {}

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    public function assertAccountReady(SocialAccount $account): void
    {
        if ($account->account_type !== 'facebook_page') {
            throw PublishException::make(ErrorCategory::AccountIneligible, 'Reels can only be published to Facebook Pages.');
        }
        foreach (['pages_manage_posts', 'pages_read_engagement'] as $scope) {
            if ($account->scopes && ! $account->hasScope($scope)) {
                throw PublishException::make(ErrorCategory::PermissionMissing, "Permission {$scope} was not granted for this Page.");
            }
        }
    }

    public function videoProblems(Video $video): array
    {
        return $this->requirements->problems($video, Platform::Facebook);
    }

    public function prepareUpload(PublishContext $ctx): void
    {
        if ($ctx->post->external_upload_id) {
            return;
        }

        $data = $this->graph->post($ctx->account->external_id.'/video_reels', $ctx->accessToken, [
            'upload_phase' => 'start',
        ], 'Facebook Reels start');

        if (empty($data['video_id'])) {
            throw PublishException::make(ErrorCategory::Unknown, 'Facebook did not return a video_id.');
        }

        $ctx->post->checkpoint(['external_upload_id' => (string) $data['video_id']]);
    }

    public function upload(PublishContext $ctx): void
    {
        $videoId = $ctx->post->external_upload_id;
        $size = $ctx->video->size_bytes;

        $status = $this->status($ctx);
        $phase = $status['uploading_phase'] ?? [];
        if (($phase['status'] ?? null) === 'complete') {
            return;
        }
        $offset = (int) ($phase['bytes_transferred'] ?? 0);

        $local = $this->storage->downloadToTemp($ctx->video);
        try {
            $body = new LimitStream(Utils::streamFor(fopen($local, 'rb')), -1, $offset);
            $result = $this->graph->rupload(
                $this->graph->ruploadUrl('video-upload', $videoId),
                $ctx->accessToken,
                $body,
                ['offset' => (string) $offset, 'file_size' => (string) $size],
                'Facebook Reels upload',
            );
        } catch (PublishException $e) {
            throw $e->category === ErrorCategory::Network ? $e->withCategory(ErrorCategory::UploadInterrupted) : $e;
        } finally {
            @unlink($local);
        }

        if (($result['success'] ?? false) !== true) {
            throw PublishException::make(ErrorCategory::UploadInterrupted, 'Facebook did not acknowledge the upload.', null, $result);
        }
    }

    public function checkProcessing(PublishContext $ctx): StepResult
    {
        $status = $this->status($ctx);
        $upload = $status['uploading_phase']['status'] ?? null;

        if ($upload === 'complete') {
            return StepResult::done();
        }
        if ($upload === 'error') {
            throw PublishException::make(ErrorCategory::UploadInterrupted, 'Facebook reported an upload error.', null, ['status' => $status]);
        }

        return StepResult::pending('Upload still being received by Facebook.');
    }

    public function publish(PublishContext $ctx): void
    {
        $result = $this->graph->post($ctx->account->external_id.'/video_reels', $ctx->accessToken, [
            'upload_phase' => 'finish',
            'video_id' => $ctx->post->external_upload_id,
            'video_state' => 'PUBLISHED',
            'description' => $ctx->content->fullCaption(),
        ], 'Facebook Reels finish');

        if (($result['success'] ?? false) !== true) {
            throw PublishException::make(ErrorCategory::Unknown, 'Facebook did not confirm the publish request.', null, $result);
        }

        $ctx->post->checkpoint([
            'external_post_id' => (string) ($result['post_id'] ?? $ctx->post->external_upload_id),
        ]);
    }

    public function confirm(PublishContext $ctx): StepResult
    {
        $status = $this->status($ctx);
        $videoStatus = $status['video_status'] ?? null;
        $publishing = $status['publishing_phase']['status'] ?? null;
        $processing = $status['processing_phase']['status'] ?? null;

        if ($videoStatus === 'error' || $processing === 'error' || $publishing === 'error') {
            $message = $status['processing_phase']['errors'][0]['message'] ?? $status['publishing_phase']['errors'][0]['message'] ?? 'Facebook could not process this Reel.';
            throw PublishException::make(ErrorCategory::MediaProcessing, $message, null, ['status' => $status]);
        }

        if ($publishing === 'complete' && in_array($videoStatus, ['ready', 'published', null], true)) {
            $videoId = $ctx->post->external_upload_id;

            return StepResult::done($ctx->post->external_post_id ?: $videoId, 'https://www.facebook.com/reel/'.$videoId);
        }

        return StepResult::pending('Facebook is processing the Reel.');
    }

    public function reconcile(PublishContext $ctx): StepResult
    {
        if (! $ctx->post->external_upload_id) {
            return StepResult::notPublished('No upload was started.');
        }

        $status = $this->status($ctx);
        $publishing = $status['publishing_phase']['status'] ?? null;

        return match ($publishing) {
            'complete', 'in_progress' => StepResult::done($ctx->post->external_post_id ?: $ctx->post->external_upload_id),
            'not_started' => StepResult::notPublished('Facebook has not started publishing this video.'),
            default => StepResult::unknown('Facebook publishing status is "'.($publishing ?? 'missing').'".'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function status(PublishContext $ctx): array
    {
        $data = $this->graph->get($ctx->post->external_upload_id, $ctx->accessToken, ['fields' => 'status'], 'Facebook Reels status');

        return (array) ($data['status'] ?? []);
    }
}
