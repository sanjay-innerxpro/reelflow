<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
