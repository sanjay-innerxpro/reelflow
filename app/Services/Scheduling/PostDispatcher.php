<?php

namespace App\Services\Scheduling;

use App\Enums\ErrorCategory;
use App\Enums\PostStatus;
use App\Exceptions\InvalidStateTransition;
use App\Jobs\PublishScheduledPostJob;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Support\ActivityLogger;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Runs every minute: claims due posts atomically and queues publish jobs.
 * A post can only be claimed by one dispatcher run at a time.
 */
class PostDispatcher
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function dispatchDue(): int
    {
        $this->recoverStaleClaims();

        if ($this->settings->bool('publishing.paused')) {
            return 0;
        }

        $publishable = SocialAccount::publishable()->pluck('id');
        if ($publishable->isEmpty()) {
            return 0;
        }

        $now = now();
        $candidates = ScheduledPost::query()
            ->whereIn('social_account_id', $publishable)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('status', PostStatus::Scheduled->value)->where('scheduled_at', '<=', $now))
                ->orWhere(fn (Builder $q) => $q->whereIn('status', [PostStatus::Retrying->value, PostStatus::Processing->value])
                    ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))))
            ->where(fn (Builder $q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', $now))
            ->orderBy('scheduled_at')
            ->limit(config('reelflow.publishing.dispatch_batch_size'))
            ->get(['id', 'platform', 'status']);

        $dispatched = 0;
        foreach ($candidates as $post) {
            if ($token = $this->claim($post)) {
                PublishScheduledPostJob::dispatch($post->id, $token)->onQueue($post->platform->queue());
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Atomic claim: succeeds for exactly one caller.
     */
    public function claim(ScheduledPost $post): ?string
    {
        $token = (string) Str::uuid();
        $now = now();

        $affected = ScheduledPost::whereKey($post->id)
            ->where('status', $post->status->value)
            ->where(fn (Builder $q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', $now))
            ->update([
                'claim_token' => $token,
                'claimed_until' => $now->copy()->addMinutes(config('reelflow.publishing.claim_timeout_minutes')),
                'updated_at' => $now,
            ]);

        return $affected === 1 ? $token : null;
    }

    /**
     * A worker that died mid-publish leaves the post in "publishing" with an
     * expired claim. Move it to "retrying": its persisted stage tells the
     * next run where to resume (and to reconcile if a publish was in flight).
     */
    public function recoverStaleClaims(): int
    {
        $stale = ScheduledPost::where('status', PostStatus::Publishing->value)
            ->where(fn (Builder $q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', now()))
            ->where('updated_at', '<', now()->subMinutes(config('reelflow.publishing.claim_timeout_minutes')))
            ->get();

        foreach ($stale as $post) {
            try {
                $post->transitionTo(PostStatus::Retrying, [
                    'claim_token' => null,
                    'claimed_until' => null,
                    'next_attempt_at' => now(),
                    'last_error_category' => ErrorCategory::Unknown,
                    'last_error_message' => 'Worker stopped during publishing; resuming from stage '.$post->stage->value.'.',
                ], 'Recovered stale publishing claim');
            } catch (InvalidStateTransition) {
                continue;
            }
        }

        return $stale->count();
    }
}
