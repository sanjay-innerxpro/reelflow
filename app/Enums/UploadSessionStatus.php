<?php

namespace App\Enums;

enum UploadSessionStatus: string
{
    case Open = 'open';
    case Assembling = 'assembling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
