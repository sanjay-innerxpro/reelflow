<?php

namespace Tests\Unit;

use App\Models\PostingRule;
use App\Services\Scheduling\SlotCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SlotCalculatorTest extends TestCase
{
    private function rule(array $attributes = []): PostingRule
    {
        return new PostingRule(array_merge([
            'timezone' => 'Asia/Kolkata',
            'daily_limit' => 5,
            'window_start' => '08:00',
            'window_end' => '21:00',
            'min_interval_minutes' => 90,
            'slot_times' => ['08:30', '11:00', '13:30', '16:30', '20:00'],
            'active_weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ], $attributes));
    }

    public function test_default_ist_slots_are_converted_to_utc(): void
    {
        $slots = (new SlotCalculator)->slotsForDay($this->rule(), CarbonImmutable::parse('2026-10-21', 'Asia/Kolkata'));

        $this->assertSame(
            ['03:00', '05:30', '08:00', '11:00', '14:30'],
            array_map(fn ($s) => $s->format('H:i'), $slots),
        );
        $this->assertSame('UTC', $slots[0]->timezoneName);
        $this->assertSame('2026-10-21', $slots[0]->toDateString());
    }

    public function test_daily_limit_caps_slots(): void
    {
        $slots = (new SlotCalculator)->slotsForDay($this->rule(['daily_limit' => 2]), CarbonImmutable::parse('2026-10-21', 'Asia/Kolkata'));
        $this->assertCount(2, $slots);
    }

    public function test_min_interval_drops_close_slots(): void
    {
        $slots = (new SlotCalculator)->slotsForDay(
            $this->rule(['slot_times' => ['09:00', '09:30', '12:00'], 'min_interval_minutes' => 60]),
            CarbonImmutable::parse('2026-10-21', 'Asia/Kolkata'),
        );
        $this->assertSame(['03:30', '06:30'], array_map(fn ($s) => $s->format('H:i'), $slots));
    }

    public function test_slots_outside_window_are_ignored(): void
    {
        $slots = (new SlotCalculator)->slotsForDay(
            $this->rule(['slot_times' => ['06:00', '10:00', '22:30'], 'window_start' => '08:00', 'window_end' => '21:00']),
            CarbonImmutable::parse('2026-10-21', 'Asia/Kolkata'),
        );
        $this->assertCount(1, $slots);
    }

    public function test_weekend_rules_and_inactive_days(): void
    {
        $calc = new SlotCalculator;
        $saturday = CarbonImmutable::parse('2026-10-24', 'Asia/Kolkata');
        $sunday = CarbonImmutable::parse('2026-10-25', 'Asia/Kolkata');
        $rule = $this->rule(['weekend_daily_limit' => 1, 'weekend_slot_times' => ['18:00'], 'active_weekdays' => [1, 2, 3, 4, 5, 6]]);

        $this->assertSame(['12:30'], array_map(fn ($s) => $s->format('H:i'), $calc->slotsForDay($rule, $saturday)));
        $this->assertSame([], $calc->slotsForDay($rule, $sunday), 'Sunday is not an active day');
    }

    public function test_evenly_spaced_when_no_slot_times(): void
    {
        $slots = (new SlotCalculator)->slotsForDay(
            $this->rule(['slot_times' => [], 'daily_limit' => 3, 'window_start' => '09:00', 'window_end' => '21:00', 'min_interval_minutes' => 60]),
            CarbonImmutable::parse('2026-10-21', 'Asia/Kolkata'),
        );
        $this->assertSame(['09:00', '15:00', '21:00'], array_map(fn ($s) => $s->setTimezone('Asia/Kolkata')->format('H:i'), $slots));
    }

    public function test_dst_timezone_is_respected(): void
    {
        $rule = $this->rule(['timezone' => 'America/New_York', 'slot_times' => ['09:00'], 'daily_limit' => 1]);
        $calc = new SlotCalculator;

        $before = $calc->slotsForDay($rule, CarbonImmutable::parse('2026-10-30', 'America/New_York'))[0];
        $after = $calc->slotsForDay($rule, CarbonImmutable::parse('2026-11-02', 'America/New_York'))[0];

        $this->assertSame('13:00', $before->format('H:i'));
        $this->assertSame('14:00', $after->format('H:i'));
    }
}
