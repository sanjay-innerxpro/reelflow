<?php

namespace App\Enums;

enum Platform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case YouTube = 'youtube';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook Reels',
            self::Instagram => 'Instagram Reels',
            self::YouTube => 'YouTube Shorts',
        };
    }

    public function queue(): string
    {
        return 'publish-'.$this->value;
    }
}
