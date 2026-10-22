<?php

namespace App\Services\Ai;

final class CaptionResult
{
    /**
     * @param  array<int, string>  $facebookHashtags
     * @param  array<int, string>  $instagramHashtags
     * @param  array<int, string>  $youtubeHashtags
     * @param  array<int, string>  $youtubeTags
     * @param  array<int, string>  $contentWarnings
     * @param  array<int, string>  $adjustments  changes made to fit platform limits
     */
    public function __construct(
        public readonly string $topic,
        public readonly ?string $category,
        public readonly string $language,
        public readonly float $confidence,
        public readonly string $facebookCaption,
        public readonly array $facebookHashtags,
        public readonly string $instagramCaption,
        public readonly array $instagramHashtags,
        public readonly string $youtubeTitle,
        public readonly string $youtubeDescription,
        public readonly array $youtubeHashtags,
        public readonly array $youtubeTags,
        public readonly array $contentWarnings,
        public readonly array $adjustments = [],
    ) {}

    public function isLowConfidence(): bool
    {
        return $this->confidence < config('reelflow.ai.low_confidence_threshold');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
