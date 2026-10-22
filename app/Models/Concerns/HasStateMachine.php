<?php

namespace App\Models\Concerns;

use App\Exceptions\InvalidStateTransition;
use App\Support\ActivityLogger;
use BackedEnum;

/**
 * Validated, race-safe status transitions.
 *
 * The update is conditional on the status we read, so two workers can never
 * both move the same record out of the same state.
 */
trait HasStateMachine
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(BackedEnum $to, array $attributes = [], ?string $reason = null): static
    {
        /** @var BackedEnum $from */
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStateTransition::make(class_basename($this), $this->getKey(), $from->value, $to->value);
        }

        $changes = array_merge($attributes, ['status' => $to]);
        $this->fill($changes);
        $dirty = $this->getDirty();
        $dirty[$this->getUpdatedAtColumn()] = $this->freshTimestampString();
        $this->setAttribute($this->getUpdatedAtColumn(), $dirty[$this->getUpdatedAtColumn()]);

        $affected = static::query()
            ->whereKey($this->getKey())
            ->where('status', $from->value)
            ->update($dirty);

        if ($affected !== 1) {
            $this->refresh();
            throw InvalidStateTransition::stale(class_basename($this), $this->getKey(), $from->value);
        }

        $this->syncOriginal();

        if ($from !== $to) {
            app(ActivityLogger::class)->statusChanged($this, $from->value, $to->value, $reason);
        }

        return $this;
    }
}
