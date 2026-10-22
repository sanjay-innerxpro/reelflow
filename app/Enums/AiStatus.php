<?php

namespace App\Enums;

enum AiStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Completed = 'completed';
    case LowConfidence = 'low_confidence';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
