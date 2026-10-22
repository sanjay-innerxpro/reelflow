<?php

namespace App\Services\Scheduling;

use App\Enums\PostStatus;
use App\Enums\PublishStage;
use App\Enums\VideoStatus;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Media\PlatformRequirements;
use App\Services\Publishing\ContentResolver;
use App\Support\ActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * User-initiated scheduling actions.
 */
class ScheduleService
{
    public function __construct(
        private readonly ContentResolver $contents,
        private readonly PlatformRequirements $requirements,
        private readonly ActivityLogger $activity,
    ) {}

    public function schedule(Video $video, SocialAccount $account, CarbonInterface $at, bool $allowReuse = false): ScheduledPost
    {
        $errors = [];
        if ($video->status !== VideoStatus::Ready) {
            $errors[] = 'The video is not ready yet.';
        }
        if ($problems = $this->requirements->problems($video, $account->platform)) {
            $errors = array_merge($errors, $problems);
        }
        if ($problem = $this->contents->problem($video->currentContent($account->platform))) {
            $errors[] = $problem;
        }
        if ($errors) {
            throw ValidationException::withMessages(['schedule' => $errors]);
        }

        return DB::transaction(function () use ($video, $account, $at, $allowReuse) {
            $existing = ScheduledPost::where('video_id', $video->id)
                ->where('social_account_id', $account->id)
                ->lockForUpdate()
                ->orderByDesc('reuse_sequence')
                ->get();

            if ($existing->isNotEmpty() && ! $allowReuse && ! $video->allow_reuse) {
                throw ValidationException::withMessages([
                    'schedule' => ['This video is already scheduled or posted on this account. Tick "post again" to reuse it intentionally.'],
                ]);
            }
            if ($existing->whereIn('status', [PostStatus::Scheduled, PostStatus::Publishing, PostStatus::Processing, PostStatus::Retrying])->isNotEmpty()) {
                throw ValidationException::withMessages(['schedule' => ['This video already has a pending post on this account.']]);
            }

            $post = ScheduledPost::create([
                'video_id' => $video->id,
                'social_account_id' => $account->id,
                'ai_content_id' => $video->currentContent($account->platform)?->id,
                'platform' => $account->platform,
                'reuse_sequence' => $existing->isEmpty() ? 0 : $existing->first()->reuse_sequence + 1,
                'scheduled_at' => $at->copy()->utc(),
                'status' => PostStatus::Scheduled,
                'max_attempts' => $account->postingRule?->max_attempts ?? config('reelflow.publishing.max_attempts'),
            ]);

            $this->activity->log('post.scheduled', $post, 'Manually scheduled for '.$at->copy()->utc()->toIso8601String());

            return $post;
        });
    }

    public function reschedule(ScheduledPost $post, CarbonInterface $at): void
    {
        if ($post->status !== PostStatus::Scheduled) {
            throw ValidationException::withMessages(['scheduled_at' => 'Only scheduled posts can be moved.']);
        }

        $post->checkpoint(['scheduled_at' => $at->copy()->utc()]);
        $this->activity->log('post.rescheduled', $post, 'Moved to '.$at->copy()->utc()->toIso8601String());
    }

    public function cancel(ScheduledPost $post): void
    {
        if (! $post->status->canTransitionTo(PostStatus::Cancelled)) {
            throw ValidationException::withMessages(['post' => "A {$post->status->value} post cannot be cancelled."]);
        }

        $post->transitionTo(PostStatus::Cancelled, ['cancelled_at' => now(), 'next_attempt_at' => null], 'Cancelled by user');
    }

    /**
     * Manual retry. A post whose outcome is uncertain (requires_review) can
     * only be retried after the user confirms it is not live.
     */
    public function retry(ScheduledPost $post, bool $confirmedNotPublished = false): void
    {
        if (! in_array($post->status, [PostStatus::Failed, PostStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['post' => 'Only failed or cancelled posts can be retried.']);
        }

        $attributes = [
            'scheduled_at' => now(),
            'next_attempt_at' => null,
            // Attempt numbers keep counting; grant a fresh retry budget.
            'max_attempts' => $post->attempt_count + ($post->socialAccount->postingRule?->max_attempts ?? config('reelflow.publishing.max_attempts')),
            'cancelled_at' => null,
        ];

        if ($post->requires_review) {
            if (! $confirmedNotPublished) {
                throw ValidationException::withMessages(['post' => 'Check the platform first: confirm the post is not live before retrying.']);
            }
            // Start over from scratch; the user confirmed nothing went live.
            $attributes += [
                'stage' => PublishStage::Pending,
                'requires_review' => false,
                'external_upload_id' => null,
                'external_container_id' => null,
                'upload_session_url' => null,
                'external_post_id' => null,
                'post_url' => null,
            ];
        }
        // Otherwise the persisted stage is kept, so the next run resumes (and
        // reconciles first if a publish call was in flight).

        $post->transitionTo(PostStatus::Scheduled, $attributes, 'Manual retry');
    }

    public function markPublishedManually(ScheduledPost $post, ?string $url): void
    {
        if (! $post->requires_review || $post->status !== PostStatus::Failed) {
            throw ValidationException::withMessages(['post' => 'Only posts awaiting manual review can be confirmed.']);
        }

        // Direct update: a human verified the outcome on the platform.
        $post->checkpoint([
            'status' => PostStatus::Published,
            'stage' => PublishStage::Confirmed,
            'post_url' => $url ?: $post->post_url,
            'published_at' => $post->published_at ?? now(),
            'confirmed_at' => now(),
            'requires_review' => false,
        ]);
        $this->activity->log('post.confirmed_manually', $post, 'Marked as published after manual verification');
    }

    /**
     * After pausing or reconnecting, drop overdue posts that were never
     * attempted so the planner re-spaces them instead of firing a burst.
     */
    public function clearOverdue(SocialAccount $account): int
    {
        return ScheduledPost::where('social_account_id', $account->id)
            ->where('status', PostStatus::Scheduled->value)
            ->where('stage', PublishStage::Pending->value)
            ->where('attempt_count', 0)
            ->where('scheduled_at', '<', now())
            ->delete();
    }

    public function retryFailedForAccount(SocialAccount $account): int
    {
        $count = 0;
        $account->scheduledPosts()->where('status', PostStatus::Failed->value)->where('requires_review', false)
            ->get()->each(function (ScheduledPost $post) use (&$count) {
                $this->retry($post);
                $count++;
            });

        return $count;
    }
}
