<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PublishStage;
use App\Models\ScheduledPost;
use App\Notifications\AccountProblemNotification;
use App\Notifications\PostOutcomeNotification;
use App\Services\Scheduling\PostDispatcher;
use App\Services\Scheduling\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    private const GRAPH = 'https://graph.facebook.com/v23.0/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        Notification::fake();
    }

    private function dispatch(): int
    {
        return app(PostDispatcher::class)->dispatchDue();
    }

    /**
     * Route faked HTTP calls by "METHOD url-fragment" to queued responses.
     *
     * @param  array<string, array<int, mixed>>  $routes
     */
    private function fakeRoutes(array $routes): void
    {
        Http::fake(function (Request $request) use (&$routes) {
            foreach ($routes as $key => &$queue) {
                [$method, $fragment] = explode(' ', $key, 2);
                if ($request->method() === $method && str_contains($request->url(), $fragment)) {
                    $next = count($queue) > 1 ? array_shift($queue) : $queue[0];

                    return is_callable($next) ? $next($request) : $next;
                }
            }

            return Http::response(['error' => ['message' => 'Unexpected call '.$request->method().' '.$request->url()]], 599);
        });
    }

    private function countSent(string $method, string $fragment, ?callable $filter = null): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && str_contains($r->url(), $fragment) && (! $filter || $filter($r)))->count();
    }

    // ---------------------------------------------------------------- Facebook

    private function facebookRoutes(array $statusQueue, $finish = null): array
    {
        return [
            'POST rupload.facebook.com/video-upload' => [Http::response(['success' => true])],
            'POST video_reels' => [
                fn (Request $r) => $r['upload_phase'] === 'start'
                    ? Http::response(['video_id' => 'fbvid1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v23.0/fbvid1'])
                    : ($finish ? $finish($r) : Http::response(['success' => true, 'post_id' => 'page_post1'])),
            ],
            'GET fbvid1' => $statusQueue,
        ];
    }

    private function fbStatus(string $upload, string $publishing = 'not_started', string $video = 'processing'): mixed
    {
        return Http::response(['status' => [
            'video_status' => $video,
            'uploading_phase' => ['status' => $upload, 'bytes_transferred' => 0],
            'processing_phase' => ['status' => 'in_progress'],
            'publishing_phase' => ['status' => $publishing],
        ], 'id' => 'fbvid1']);
    }

    public function test_facebook_reel_full_publish_flow(): void
    {
        [$post] = $this->duePost(Platform::Facebook);
        $this->fakeRoutes($this->facebookRoutes([
            $this->fbStatus('in_progress'),
            $this->fbStatus('complete'),
            $this->fbStatus('complete', 'complete', 'ready'),
        ]));

        $this->assertSame(1, $this->dispatch());

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(PublishStage::Confirmed, $post->stage);
        $this->assertSame('fbvid1', $post->external_upload_id);
        $this->assertSame('https://www.facebook.com/reel/fbvid1', $post->post_url);
        $this->assertNotNull($post->published_at);
        $this->assertSame(1, $post->attempts()->count());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rupload.facebook.com') && $r->hasHeader('Authorization', 'OAuth '.$post->socialAccount->access_token) && $r->hasHeader('file_size'));
        Http::assertSent(fn (Request $r) => ($r['upload_phase'] ?? null) === 'finish' && str_contains($r['description'], '#masalapasta'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'access_token='));
        Notification::assertSentOnDemand(PostOutcomeNotification::class);
    }

    public function test_facebook_timeout_on_publish_is_reconciled_without_reposting(): void
    {
        [$post] = $this->duePost(Platform::Facebook);
        $finishCalls = 0;
        $this->fakeRoutes($this->facebookRoutes(
            [$this->fbStatus('in_progress'), $this->fbStatus('complete'), $this->fbStatus('complete', 'complete', 'ready')],
            function () use (&$finishCalls) {
                $finishCalls++;
                throw new ConnectionException('cURL error 28: timed out');
            },
        ));

        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Retrying, $post->status);
        $this->assertSame(PublishStage::PublishRequested, $post->stage);
        $this->assertSame(ErrorCategory::Ambiguous, $post->last_error_category);

        // The platform actually accepted it: reconciliation finds it published.
        $this->travel(2)->hours();
        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(1, $finishCalls, 'Finish must not be sent twice');
    }

    public function test_facebook_reconcile_publishes_again_only_when_not_started(): void
    {
        [$post] = $this->duePost(Platform::Facebook, [
            'status' => PostStatus::Retrying, 'stage' => PublishStage::PublishRequested,
            'external_upload_id' => 'fbvid1', 'next_attempt_at' => now()->subMinute(), 'attempt_count' => 1,
        ]);
        $this->fakeRoutes($this->facebookRoutes([
            $this->fbStatus('complete', 'not_started'),
            $this->fbStatus('complete', 'complete', 'ready'),
        ]));

        $this->dispatch();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->assertSame(1, $this->countSent('POST', 'video_reels', fn ($r) => $r['upload_phase'] === 'finish'));
    }

    public function test_unverifiable_outcome_requires_manual_review(): void
    {
        [$post] = $this->duePost(Platform::Facebook, [
            'status' => PostStatus::Retrying, 'stage' => PublishStage::PublishRequested,
            'external_upload_id' => 'fbvid1', 'next_attempt_at' => now()->subMinute(), 'attempt_count' => 1,
        ]);
        $this->fakeRoutes($this->facebookRoutes([$this->fbStatus('complete', 'weird_state')]));

        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertTrue($post->requires_review);
        $this->assertSame(0, $this->countSent('POST', 'video_reels'));

        $schedule = app(ScheduleService::class);
        try {
            $schedule->retry($post);
            $this->fail('Retry without confirmation must be refused');
        } catch (ValidationException) {
        }

        $schedule->retry($post, confirmedNotPublished: true);
        $post->refresh();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertSame(PublishStage::Pending, $post->stage);
        $this->assertNull($post->external_upload_id);
    }

    // --------------------------------------------------------------- Instagram

    private function instagramRoutes(array $containerStatus, array $overrides = []): array
    {
        return array_merge([
            'GET content_publishing_limit' => [Http::response(['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]])],
            'POST rupload.facebook.com/ig-api-upload/v23.0/igc1' => [Http::response(['success' => true])],
            'POST /media_publish' => [Http::response(['id' => 'igmedia1'])],
            'POST /media' => [Http::response(['id' => 'igc1'])],
            'GET igc1' => $containerStatus,
            'GET igmedia1' => [Http::response(['id' => 'igmedia1', 'permalink' => 'https://www.instagram.com/reel/ABC123/'])],
        ], $overrides);
    }

    public function test_instagram_waits_for_processing_then_publishes(): void
    {
        [$post] = $this->duePost(Platform::Instagram);
        $this->fakeRoutes($this->instagramRoutes([
            Http::response(['status_code' => 'IN_PROGRESS']),
            Http::response(['status_code' => 'FINISHED']),
        ]));

        $this->dispatch();
        $post->refresh();
        $this->assertSame(PostStatus::Processing, $post->status);
        $this->assertSame('igc1', $post->external_container_id);
        $this->assertSame(0, $this->countSent('POST', 'media_publish'), 'Never publish before processing finishes');

        $this->travel(2)->minutes();
        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame('igmedia1', $post->external_post_id);
        $this->assertSame('https://www.instagram.com/reel/ABC123/', $post->post_url);
        $this->assertSame(1, $post->attempts()->count(), 'Polling does not consume attempts');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media') && $r['media_type'] === 'REELS' && $r['upload_type'] === 'resumable');
    }

    public function test_instagram_expired_token_pauses_account_and_notifies(): void
    {
        [$post, , $account] = $this->duePost(Platform::Instagram);
        $this->fakeRoutes($this->instagramRoutes([], [
            'POST /media' => [Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463]], 400)],
        ]));

        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(ErrorCategory::TokenExpired, $post->last_error_category);
        $this->assertSame(AccountStatus::Expired, $account->fresh()->status);
        Notification::assertSentOnDemand(AccountProblemNotification::class);

        // Other posts for the account are no longer dispatched.
        $this->duePost(Platform::Instagram);
        ScheduledPost::query()->where('id', '!=', $post->id)->update(['social_account_id' => $account->id]);
        $this->assertSame(0, $this->dispatch());
    }

    public function test_instagram_quota_exhaustion_is_retried_later(): void
    {
        [$post] = $this->duePost(Platform::Instagram);
        $this->fakeRoutes($this->instagramRoutes([], [
            'GET content_publishing_limit' => [Http::response(['data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100]]]])],
        ]));

        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Retrying, $post->status);
        $this->assertSame(ErrorCategory::QuotaExhausted, $post->last_error_category);
        $this->assertTrue($post->next_attempt_at->gte(now()->addMinutes(59)));
        $this->assertSame(0, $this->countSent('POST', '/media'));
    }

    public function test_instagram_invalid_video_fails_without_retry(): void
    {
        [$post] = $this->duePost(Platform::Instagram);
        $this->fakeRoutes($this->instagramRoutes([
            Http::response(['status_code' => 'ERROR', 'status' => 'Error: Media upload has failed with error code 2207026']),
        ]));

        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(ErrorCategory::InvalidVideo, $post->last_error_category);
        Notification::assertSentOnDemand(PostOutcomeNotification::class);
    }

    public function test_transient_errors_retry_with_backoff_until_max_attempts(): void
    {
        [$post] = $this->duePost(Platform::Instagram, ['max_attempts' => 2]);
        $this->fakeRoutes($this->instagramRoutes([], [
            'POST /media' => [Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2, 'is_transient' => true]], 503)],
        ]));

        $this->dispatch();
        $post->refresh();
        $this->assertSame(PostStatus::Retrying, $post->status);
        $this->assertTrue($post->next_attempt_at->isFuture());
        $this->assertSame(0, $this->dispatch(), 'Not retried before backoff elapses');

        $this->travel(3)->hours();
        $this->dispatch();
        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(2, $post->attempt_count);
    }

    // ----------------------------------------------------------------- YouTube

    private function youtubeRoutes(array $overrides = []): array
    {
        return array_merge([
            'POST upload/youtube/v3/videos' => [Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=SESSION1'])],
            'PUT upload_id=SESSION1' => [fn (Request $r) => str_contains($r->header('Content-Range')[0] ?? '', '*/')
                ? Http::response('', 308)
                : Http::response(['id' => 'ytvid1', 'status' => ['uploadStatus' => 'uploaded']], 200)],
            'GET youtube/v3/videos' => [Http::response(['items' => [['id' => 'ytvid1', 'status' => ['uploadStatus' => 'processed', 'privacyStatus' => 'public']]]])],
        ], $overrides);
    }

    public function test_youtube_resumable_upload_flow(): void
    {
        [$post, $video] = $this->duePost(Platform::YouTube);
        $this->fakeRoutes($this->youtubeRoutes());

        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame('ytvid1', $post->external_post_id);
        $this->assertSame('https://www.youtube.com/shorts/ytvid1', $post->post_url);

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'uploadType=resumable')) {
                return false;
            }

            return $r['snippet']['title'] === 'Quick masala pasta'
                && $r['status']['privacyStatus'] === 'public'
                && $r['status']['selfDeclaredMadeForKids'] === false
                && $r->hasHeader('X-Upload-Content-Length');
        });
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && ($r->header('Content-Range')[0] ?? '') === 'bytes 0-'.($video->size_bytes - 1).'/'.$video->size_bytes);
    }

    public function test_youtube_interrupted_upload_resumes_from_committed_offset(): void
    {
        [$post, $video] = $this->duePost(Platform::YouTube, [
            'status' => PostStatus::Retrying, 'stage' => PublishStage::Prepared, 'attempt_count' => 1,
            'upload_session_url' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=SESSION1',
            'next_attempt_at' => now()->subMinute(),
        ]);
        $this->fakeRoutes($this->youtubeRoutes([
            'PUT upload_id=SESSION1' => [fn (Request $r) => str_contains($r->header('Content-Range')[0] ?? '', '*/')
                ? Http::response('', 308, ['Range' => 'bytes=0-99'])
                : Http::response(['id' => 'ytvid1'], 201)],
        ]));

        $this->dispatch();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->assertSame(0, $this->countSent('POST', 'uploadType=resumable'), 'No second upload session is created');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_starts_with($r->header('Content-Range')[0] ?? '', 'bytes 100-'));
    }

    public function test_youtube_session_already_completed_is_not_uploaded_again(): void
    {
        [$post] = $this->duePost(Platform::YouTube, [
            'status' => PostStatus::Retrying, 'stage' => PublishStage::Prepared, 'attempt_count' => 1,
            'upload_session_url' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=SESSION1',
            'next_attempt_at' => now()->subMinute(),
        ]);
        $this->fakeRoutes($this->youtubeRoutes([
            'PUT upload_id=SESSION1' => [Http::response(['id' => 'ytvid1'], 200)],
        ]));

        $this->dispatch();

        $this->assertSame('ytvid1', $post->fresh()->external_post_id);
        $this->assertSame(1, $this->countSent('PUT', 'upload_id=SESSION1'), 'Only the status query, no byte upload');
    }

    public function test_youtube_quota_exhaustion_pauses_channel_until_reset(): void
    {
        [$post, , $account] = $this->duePost(Platform::YouTube);
        $this->fakeRoutes($this->youtubeRoutes([
            'POST upload/youtube/v3/videos' => [Http::response(['error' => ['code' => 403, 'message' => 'quota', 'errors' => [['reason' => 'quotaExceeded']]]], 403)],
        ]));

        $this->dispatch();

        $post->refresh();
        $account->refresh();
        $this->assertSame(PostStatus::Retrying, $post->status);
        $this->assertSame(ErrorCategory::QuotaExhausted, $post->last_error_category);
        $this->assertTrue($account->paused_until->isFuture());
        $this->assertFalse($account->isPublishable());
        $this->assertSame('00:10', $post->next_attempt_at->setTimezone('America/Los_Angeles')->format('H:i'));
    }

    public function test_youtube_refreshes_expiring_token(): void
    {
        [$post, , $account] = $this->duePost(Platform::YouTube, [], ['token_expires_at' => now()->subMinute()]);
        $this->fakeRoutes($this->youtubeRoutes([
            'POST oauth2.googleapis.com/token' => [Http::response(['access_token' => 'ya29.fresh', 'expires_in' => 3599])],
        ]));

        $this->dispatch();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->assertSame('ya29.fresh', $account->fresh()->access_token);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uploadType=resumable') && $r->hasHeader('Authorization', 'Bearer ya29.fresh'));
    }

    public function test_youtube_revoked_refresh_token_flags_account(): void
    {
        [$post, , $account] = $this->duePost(Platform::YouTube, [], ['token_expires_at' => now()->subMinute()]);
        $this->fakeRoutes($this->youtubeRoutes([
            'POST oauth2.googleapis.com/token' => [Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)],
        ]));

        $this->dispatch();

        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
        $this->assertSame(AccountStatus::Expired, $account->fresh()->status);
        $this->assertSame(0, $this->countSent('POST', 'uploadType=resumable'));
    }

    // ----------------------------------------------------------- Eligibility

    public function test_post_without_publishable_caption_never_calls_the_platform(): void
    {
        [$post, $video] = $this->duePost(Platform::Facebook);
        $video->aiContents()->update(['validation_status' => 'low_confidence']);
        Http::fake();

        $this->dispatch();

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(ErrorCategory::InvalidMetadata, $post->last_error_category);
        Http::assertNothingSent();
    }

    public function test_ineligible_video_format_is_rejected_before_upload(): void
    {
        [$post, $video] = $this->duePost(Platform::Facebook);
        $video->update(['width' => 1920, 'height' => 1080]);
        Http::fake();

        $this->dispatch();

        $this->assertSame(ErrorCategory::InvalidVideo, $post->fresh()->last_error_category);
        Http::assertNothingSent();
    }
}
