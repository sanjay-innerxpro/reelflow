<?php

namespace App\Services\Publishing;

use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PublishStage;
use App\Exceptions\InvalidStateTransition;
use App\Models\PublishAttempt;
use App\Models\ScheduledPost;
use App\Services\Notifications\NotificationService;
use App\Services\Platforms\TokenManager;
use App\Services\Storage\VideoStorage;
use App\Support\ActivityLogger;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives one scheduled post through the publishing stages. Each run resumes
 * from the persisted stage, so a retry never repeats a step that already
 * succeeded remotely, and an uncertain publish is reconciled before any
 * second attempt.
 */
class PublishingService
{
    public function __construct(
        private readonly PublisherRegistry $publishers,
        private readonly TokenManager $tokens,
        private readonly ContentResolver $contents,
        private readonly VideoStorage $storage,
        private readonly RetryPolicy $retry,
        private readonly AccountHealthService $accountHealth,
        private readonly NotificationService $notifications,
        private readonly ActivityLogger $activity,
    ) {}

    public function run(int $postId, string $claimToken): void
    {
        $lock = Cache::lock('publish-post:'.$postId, 3600);
        if (! $lock->get()) {
            return;
        }

        try {
            $post = ScheduledPost::with(['socialAccount.postingRule', 'video'])->find($postId);
            if (! $post || $post->claim_token !== $claimToken) {
                return;
            }

            $this->process($post);
        } finally {
            ScheduledPost::whereKey($postId)->where('claim_token', $claimToken)
                ->update(['claim_token' => null, 'claimed_until' => null]);
            $lock->release();
        }
    }

    private function process(ScheduledPost $post): void
    {
        if (! in_array($post->status, [PostStatus::Scheduled, PostStatus::Retrying, PostStatus::Processing], true)) {
            return;
        }

        $account = $post->socialAccount;
        if (! $account->isPublishable()) {
            // Account paused or needs reconnecting: leave the post waiting.
            $post->checkpoint(['next_attempt_at' => now()->addMinutes(15)]);

            return;
        }

        $isPoll = $post->status === PostStatus::Processing;
        $attempt = $this->beginAttempt($post, $isPoll);

        try {
            $post->transitionTo(PostStatus::Publishing, [
                'publishing_started_at' => $post->publishing_started_at ?? now(),
            ], $isPoll ? null : 'Attempt '.$post->attempt_count);

            $publisher = $this->publishers->for($post->platform);
            $token = $this->tokens->accessToken($account);
            $publisher->assertAccountReady($account);

            $content = $this->contents->publishable($post->video, $post->platform);
            if (! $content) {
                throw PublishException::make(ErrorCategory::InvalidMetadata, $this->contents->problem($post->video->currentContent($post->platform)) ?? 'No publishable caption.');
            }
            if ($post->ai_content_id !== $content->id) {
                $post->checkpoint(['ai_content_id' => $content->id]);
            }

            if ($post->stage === PublishStage::Pending) {
                if ($problems = $publisher->videoProblems($post->video)) {
                    throw PublishException::make(ErrorCategory::InvalidVideo, implode(' ', $problems));
                }
                if (! $this->storage->exists($post->video)) {
                    throw PublishException::make(ErrorCategory::InvalidVideo, 'The video file is not available in storage.');
                }
            }

            $this->advance(new PublishContext($post, $account, $post->video, $content, $token), $publisher, $attempt);
        } catch (PublishException $e) {
            $this->handleFailure($post, $e, $attempt);
        } catch (InvalidStateTransition $e) {
            // Another worker changed the post; nothing to do.
            Log::notice('Publish skipped: '.$e->getMessage());
            $this->finishAttempt($attempt, 'skipped', null, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->handleFailure($post, new PublishException(ErrorCategory::Unknown, $e->getMessage(), previous: $e), $attempt);
        }
    }

    private function advance(PublishContext $ctx, SocialPublisherInterface $publisher, PublishAttempt $attempt): void
    {
        $post = $ctx->post;

        for ($guard = 0; $guard < 12; $guard++) {
            switch ($post->stage) {
                case PublishStage::Pending:
                    $publisher->prepareUpload($ctx);
                    $this->stage($post, PublishStage::Prepared);
                    break;

                case PublishStage::Prepared:
                    $publisher->upload($ctx);
                    $this->stage($post, PublishStage::Uploaded, ['processing_started_at' => now()]);
                    break;

                case PublishStage::Uploaded:
                    $result = $publisher->checkProcessing($ctx);
                    if ($result->is(StepResult::PENDING)) {
                        $this->poll($post, $attempt, $result->note);

                        return;
                    }
                    $this->stage($post, PublishStage::Processed);
                    break;

                case PublishStage::Processed:
                    // Persist intent first: if we crash or time out during the
                    // call, the next run reconciles instead of publishing again.
                    $this->stage($post, PublishStage::PublishRequested);
                    try {
                        $publisher->publish($ctx);
                    } catch (PublishException $e) {
                        if (in_array($e->category, [ErrorCategory::Network, ErrorCategory::Unknown, ErrorCategory::Ambiguous], true)) {
                            throw $e->withCategory(ErrorCategory::Ambiguous, 'Publish result unknown:');
                        }
                        // A definite rejection means nothing was published.
                        $this->stage($post, PublishStage::Processed);
                        throw $e;
                    }
                    $this->stage($post, PublishStage::Published, ['processing_started_at' => now()]);
                    break;

                case PublishStage::PublishRequested:
                    $result = $publisher->reconcile($ctx);
                    if ($result->is(StepResult::DONE)) {
                        $this->stage($post, PublishStage::Published, array_filter([
                            'external_post_id' => $result->externalId ?? $post->external_post_id,
                            'post_url' => $result->url ?? $post->post_url,
                            'processing_started_at' => now(),
                        ]));
                        $this->activity->log('post.reconciled', $post, 'Reconciled as published: '.($result->note ?? ''));
                    } elseif ($result->is(StepResult::NOT_PUBLISHED)) {
                        $this->stage($post, PublishStage::Processed);
                        $this->activity->log('post.reconciled', $post, 'Reconciled as not published: '.($result->note ?? ''));
                    } else {
                        throw ReviewRequiredException::because('Could not verify whether the post was published: '.$result->note);
                    }
                    break;

                case PublishStage::Published:
                    $result = $publisher->confirm($ctx);
                    if ($result->is(StepResult::PENDING)) {
                        $this->poll($post, $attempt, $result->note);

                        return;
                    }
                    $this->markPublished($post, $attempt, $result);

                    return;

                case PublishStage::Confirmed:
                    $this->markPublished($post, $attempt, StepResult::done());

                    return;
            }
        }

        throw PublishException::make(ErrorCategory::Unknown, 'Publishing did not converge.');
    }

    private function poll(ScheduledPost $post, PublishAttempt $attempt, ?string $note): void
    {
        $timeout = config('reelflow.publishing.processing_timeout_minutes');
        if ($post->processing_started_at && $post->processing_started_at->lt(now()->subMinutes($timeout))) {
            if ($post->stage === PublishStage::Published) {
                // It may well be live; never report success we cannot verify.
                throw ReviewRequiredException::because("Platform still processing after {$timeout} minutes. Check the post manually.");
            }
            throw new PublishException(ErrorCategory::MediaProcessing, "Processing did not finish within {$timeout} minutes.", resetStage: $post->platform === Platform::Instagram);
        }

        $post->transitionTo(PostStatus::Processing, [
            'next_attempt_at' => now()->addSeconds(config('reelflow.publishing.processing_poll_seconds')),
        ]);
        $this->finishAttempt($attempt, PublishAttempt::POLL, null, $note);
    }

    private function markPublished(ScheduledPost $post, PublishAttempt $attempt, StepResult $result): void
    {
        $post->transitionTo(PostStatus::Published, [
            'stage' => PublishStage::Confirmed,
            'external_post_id' => $result->externalId ?? $post->external_post_id,
            'post_url' => $result->url ?? $post->post_url,
            'published_at' => $post->published_at ?? now(),
            'confirmed_at' => now(),
            'next_attempt_at' => null,
            'last_error_category' => null,
            'last_error_message' => $result->note,
            'requires_review' => false,
        ], 'Published'.($post->post_url ? ': '.$post->post_url : ''));

        $this->finishAttempt($attempt, 'published', null, $result->note);
        $this->notifications->postPublished($post);
    }

    private function handleFailure(ScheduledPost $post, PublishException $e, PublishAttempt $attempt): void
    {
        $post->refresh();
        $category = $e->category;
        $rule = $post->socialAccount->postingRule;

        if ($e->resetStage) {
            $post->checkpoint([
                'stage' => PublishStage::Pending,
                'external_container_id' => null,
                'external_upload_id' => $post->platform === Platform::Facebook ? null : $post->external_upload_id,
                'upload_session_url' => null,
                'processing_started_at' => null,
            ]);
        }

        $reviewRequired = $e instanceof ReviewRequiredException;
        // An ambiguous publish is retried only through reconciliation.
        $retryable = ! $reviewRequired
            && ($category->isRetryable() || ($category === ErrorCategory::Ambiguous && $post->stage === PublishStage::PublishRequested));
        $canRetry = $retryable
            && ($rule?->auto_retry ?? true)
            && $post->attempt_count < $post->max_attempts;

        $base = [
            'last_error_category' => $category,
            'last_error_message' => $e->getMessage(),
        ];

        if ($category->pausesAccount()) {
            $this->accountHealth->flag($post->socialAccount, $category, $e->getMessage());
        }

        if ($category === ErrorCategory::QuotaExhausted && $post->platform === Platform::YouTube) {
            $this->accountHealth->pauseUntil($post->socialAccount, $this->retry->youtubeQuotaReset(), 'YouTube upload quota exhausted.');
        }

        if ($canRetry && ! $category->pausesAccount()) {
            $next = $this->retry->nextAttemptAt($post->attempt_count, $e, $post->platform);
            $this->moveTo($post, PostStatus::Retrying, $base + ['next_attempt_at' => $next], "Retry scheduled ({$category->value})");
            $this->finishAttempt($attempt, 'error', $e, retry: PublishAttempt::RETRY, retryAt: $next);

            return;
        }

        $this->moveTo($post, PostStatus::Failed, $base + [
            'next_attempt_at' => null,
            'requires_review' => $reviewRequired || ($category === ErrorCategory::Ambiguous),
        ], "Failed ({$category->value})");
        $this->finishAttempt($attempt, 'error', $e, retry: PublishAttempt::FAIL);

        if (! $category->pausesAccount()) {
            $this->notifications->postFailed($post);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function moveTo(ScheduledPost $post, PostStatus $status, array $attributes, string $reason): void
    {
        if ($post->status !== PostStatus::Publishing) {
            $post->checkpoint($attributes);

            return;
        }
        $post->transitionTo($status, $attributes, $reason);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function stage(ScheduledPost $post, PublishStage $stage, array $extra = []): void
    {
        $post->checkpoint(array_merge($extra, ['stage' => $stage]));
    }

    private function beginAttempt(ScheduledPost $post, bool $isPoll): PublishAttempt
    {
        if ($isPoll) {
            $latest = $post->attempts()->latest('attempt_number')->first();
            if ($latest) {
                return $latest;
            }
        }

        $post->forceFill(['attempt_count' => $post->attempt_count + 1])->save();

        return PublishAttempt::create([
            'scheduled_post_id' => $post->id,
            'attempt_number' => $post->attempt_count,
            'stage' => $post->stage->value,
            'started_at' => now(),
        ]);
    }

    private function finishAttempt(PublishAttempt $attempt, string $outcome, ?PublishException $e = null, ?string $note = null, ?string $retry = null, $retryAt = null): void
    {
        $attempt->fill([
            'completed_at' => now(),
            'outcome' => $outcome,
            'retry_decision' => $retry ?? ($outcome === PublishAttempt::POLL ? PublishAttempt::POLL : PublishAttempt::NONE),
            'retry_at' => $retryAt,
        ]);

        if ($e) {
            $attempt->fill([
                'http_status' => $e->httpStatus,
                'response_meta' => $e->responseMeta ? SecretRedactor::redact($e->responseMeta) : null,
                'error_category' => $e->category,
                'error_message' => $e->getMessage(),
            ]);
        } elseif ($note) {
            $attempt->response_meta = array_merge($attempt->response_meta ?? [], ['note' => SecretRedactor::truncate($note, 300)]);
        }

        $attempt->save();
    }
}
