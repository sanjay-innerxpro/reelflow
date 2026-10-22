<?php

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\Video;

/**
 * One implementation per destination. Every step must persist any remote
 * identifier it receives via $ctx->post->checkpoint() before returning, so
 * a crash or timeout never loses track of remote state.
 *
 * All methods throw PublishException for classified failures.
 */
interface SocialPublisherInterface
{
    public function platform(): Platform;

    /** Account is connected, token valid, required permissions granted. */
    public function assertAccountReady(SocialAccount $account): void;

    /** @return array<int, string> reasons the video cannot be posted here */
    public function videoProblems(Video $video): array;

    /** Initialise the upload / create the media container. */
    public function prepareUpload(PublishContext $ctx): void;

    /** Transfer the bytes (no-op where the platform fetches by URL). */
    public function upload(PublishContext $ctx): void;

    /** Remote processing of the uploaded media before publishing. */
    public function checkProcessing(PublishContext $ctx): StepResult;

    /** The step that makes the post public. */
    public function publish(PublishContext $ctx): void;

    /** Verify the post is live; returns external post ID and URL. */
    public function confirm(PublishContext $ctx): StepResult;

    /**
     * Determine what happened after an ambiguous publish call:
     * done (it is published), notPublished (safe to publish again) or unknown.
     */
    public function reconcile(PublishContext $ctx): StepResult;
}
