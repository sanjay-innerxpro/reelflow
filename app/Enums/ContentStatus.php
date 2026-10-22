<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Valid = 'valid';
    case LowConfidence = 'low_confidence';
    case Edited = 'edited';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Whether content in this state may be published without a human looking at it.
     */
    public function isPublishable(bool $approvalRequired): bool
    {
        if ($approvalRequired) {
            return $this === self::Approved;
        }

        return in_array($this, [self::Valid, self::Edited, self::Approved], true);
    }
}
