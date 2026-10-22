<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Connected = 'connected';
    case Paused = 'paused';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Ineligible = 'ineligible';
    case Disconnected = 'disconnected';

    public function canPublish(): bool
    {
        return $this === self::Connected;
    }

    public function needsReconnect(): bool
    {
        return in_array($this, [self::Expired, self::Revoked], true);
    }
}
