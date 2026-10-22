<?php

namespace App\Services\Publishing;

use App\Enums\Platform;
use App\Models\AiContent;
use App\Models\Video;
use App\Support\Settings;

class ContentResolver
{
    public function __construct(private readonly Settings $settings) {}

    public function publishable(Video $video, Platform $platform): ?AiContent
    {
        $content = $video->currentContent($platform);
        if (! $content) {
            return null;
        }

        return $this->problem($content) === null ? $content : null;
    }

    public function problem(?AiContent $content): ?string
    {
        if (! $content) {
            return 'No caption has been generated or written for this platform.';
        }

        if (! $content->validation_status->isPublishable($this->settings->bool('publishing.require_approval'))) {
            return match ($content->validation_status->value) {
                'low_confidence' => 'AI confidence is low; review and approve the caption first.',
                'rejected' => 'The caption was rejected.',
                default => 'The caption needs approval before publishing.',
            };
        }

        if ($content->platform === Platform::YouTube && blank($content->title)) {
            return 'A YouTube title is required.';
        }

        if ($content->platform !== Platform::YouTube && blank($content->caption) && empty($content->hashtags)) {
            return 'The caption is empty.';
        }

        return null;
    }
}
