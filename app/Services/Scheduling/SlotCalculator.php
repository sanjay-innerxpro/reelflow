<?php

namespace App\Services\Scheduling;

use App\Models\PostingRule;
use Carbon\CarbonImmutable;

/**
 * Pure calculation of posting slots for one local day. No database access,
 * so the rules are easy to test.
 */
class SlotCalculator
{
    /**
     * @return array<int, CarbonImmutable> slot times in UTC, ascending
     */
    public function slotsForDay(PostingRule $rule, CarbonImmutable $localDate): array
    {
        $tz = $rule->timezone;
        $day = $localDate->setTimezone($tz)->startOfDay();
        $isoDay = (int) $day->isoWeekday(); // 1 = Monday … 7 = Sunday
        $weekend = $isoDay >= 6;

        $activeDays = $rule->active_weekdays ?: [1, 2, 3, 4, 5, 6, 7];
        if (! in_array($isoDay, array_map('intval', $activeDays), true)) {
            return [];
        }

        $limit = $weekend && $rule->weekend_daily_limit !== null ? $rule->weekend_daily_limit : $rule->daily_limit;
        if ($limit <= 0) {
            return [];
        }

        $windowStart = $this->at($day, $rule->window_start ?: '00:00');
        $windowEnd = $this->at($day, $rule->window_end ?: '23:59');
        if ($windowEnd->lte($windowStart)) {
            return [];
        }

        $configured = $weekend && ! empty($rule->weekend_slot_times) ? $rule->weekend_slot_times : ($rule->slot_times ?? []);

        $candidates = $configured
            ? collect($configured)->map(fn ($t) => $this->at($day, $t))->sort()->values()->all()
            : $this->evenlySpaced($windowStart, $windowEnd, $limit);

        $minGap = max(0, (int) $rule->min_interval_minutes);
        $slots = [];
        foreach ($candidates as $slot) {
            if ($slot->lt($windowStart) || $slot->gt($windowEnd)) {
                continue;
            }
            $previous = end($slots);
            if ($previous && $previous->diffInMinutes($slot) < $minGap) {
                continue;
            }
            $slots[] = $slot;
            if (count($slots) >= $limit) {
                break;
            }
        }

        return array_map(fn (CarbonImmutable $s) => $s->utc(), $slots);
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function evenlySpaced(CarbonImmutable $start, CarbonImmutable $end, int $count): array
    {
        if ($count === 1) {
            return [$start];
        }
        $step = intdiv((int) $start->diffInMinutes($end), $count - 1);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $start->addMinutes($step * $i);
        }

        return $out;
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, 0));

        return $day->setTime($h, $m);
    }
}
