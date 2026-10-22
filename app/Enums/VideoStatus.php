<?php

namespace App\Enums;

enum VideoStatus: string
{
    case Discovered = 'discovered';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Discovered => [self::Uploading, self::Uploaded, self::Failed, self::Cancelled],
            self::Uploading => [self::Uploading, self::Uploaded, self::Failed, self::Cancelled],
            self::Uploaded => [self::Processing, self::Failed, self::Cancelled],
            self::Processing => [self::Ready, self::Failed, self::Cancelled],
            self::Ready => [self::Processing, self::Cancelled],
            self::Failed => [self::Uploading, self::Processing, self::Cancelled],
            self::Cancelled => [self::Processing],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function hasStoredFile(): bool
    {
        return in_array($this, [self::Uploaded, self::Processing, self::Ready], true);
    }
}
