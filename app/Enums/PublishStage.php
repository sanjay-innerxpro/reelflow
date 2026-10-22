<?php

namespace App\Enums;

/**
 * Progress marker persisted on a scheduled post so a retry resumes from the
 * last confirmed step instead of repeating a step that may have been public.
 */
enum PublishStage: string
{
    case Pending = 'pending';
    case Prepared = 'prepared';
    case Uploaded = 'uploaded';
    case Processed = 'processed';
    case PublishRequested = 'publish_requested';
    case Published = 'published';
    case Confirmed = 'confirmed';
}
