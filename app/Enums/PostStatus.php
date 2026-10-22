<?php

namespace App\Enums;

enum PostStatus: string
{
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Processing = 'processing';
    case Published = 'published';
    case Retrying = 'retrying';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Publishing, self::Cancelled, self::Failed],
            self::Publishing => [self::Processing, self::Published, self::Retrying, self::Failed],
            self::Processing => [self::Publishing, self::Published, self::Retrying, self::Failed],
            self::Retrying => [self::Publishing, self::Cancelled, self::Failed],
            self::Failed => [self::Scheduled, self::Cancelled],
            self::Published => [],
            self::Cancelled => [self::Scheduled],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Published, self::Cancelled], true);
    }

    /**
     * Statuses that occupy a slot in an account's schedule.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return [self::Scheduled->value, self::Publishing->value, self::Processing->value, self::Retrying->value, self::Published->value];
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'blue',
            self::Publishing, self::Processing => 'amber',
            self::Published => 'green',
            self::Retrying => 'orange',
            self::Failed => 'red',
            self::Cancelled => 'gray',
        };
    }
}
