<?php

namespace App\Services\Media;

use App\Enums\Platform;
use App\Models\Video;

/**
 * Format rules for each destination. These reflect documented platform limits
 * at the time of writing; platforms change them, so they are deliberately
 * simple to update.
 */
class PlatformRequirements
{
    /**
     * Problems that block publishing the video to the platform.
     *
     * @return array<int, string>
     */
    public function problems(Video $video, Platform $platform): array
    {
        $problems = [];
        $duration = $video->duration_seconds;
        $ratio = $video->aspectRatio();

        if ($duration === null || $ratio === null) {
            return ['Video metadata has not been extracted yet.'];
        }

        switch ($platform) {
            case Platform::Facebook:
                if ($duration < 3 || $duration > 90) {
                    $problems[] = 'Facebook Reels must be 3–90 seconds long.';
                }
                if ($ratio > 1) {
                    $problems[] = 'Facebook Reels must be vertical (9:16 recommended).';
                }
                if (($video->width ?? 0) < 540 || ($video->height ?? 0) < 960) {
                    $problems[] = 'Facebook Reels require at least 540×960 pixels.';
                }
                break;

            case Platform::Instagram:
                if ($duration < 3 || $duration > 900) {
                    $problems[] = 'Instagram Reels must be 3 seconds to 15 minutes long.';
                }
                if ($ratio < 0.01 || $ratio > 10) {
                    $problems[] = 'Instagram Reels aspect ratio is outside 0.01:1–10:1.';
                }
                if ($video->size_bytes > 1024 * 1024 * 1024) {
                    $problems[] = 'Instagram Reels must be smaller than 1 GB.';
                }
                break;

            case Platform::YouTube:
                if (config('reelflow.google.require_shorts_format')) {
                    if ($duration > config('reelflow.google.shorts_max_seconds')) {
                        $problems[] = 'YouTube Shorts must be at most '.config('reelflow.google.shorts_max_seconds').' seconds.';
                    }
                    if ($ratio > 1) {
                        $problems[] = 'YouTube Shorts must be vertical or square.';
                    }
                }
                break;
        }

        return $problems;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function allProblems(Video $video): array
    {
        $out = [];
        foreach (Platform::cases() as $platform) {
            $problems = $this->problems($video, $platform);
            if ($problems) {
                $out[$platform->value] = $problems;
            }
        }

        return $out;
    }
}
