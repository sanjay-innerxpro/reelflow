<?php

namespace App\Support;

use Carbon\CarbonInterface;

class DisplayTime
{
    public static function timezone(): string
    {
        return config('reelflow.display_timezone');
    }

    public static function format(?CarbonInterface $time, string $format = 'd M Y, H:i'): string
    {
        return $time ? $time->copy()->setTimezone(self::timezone())->format($format) : '—';
    }
}
