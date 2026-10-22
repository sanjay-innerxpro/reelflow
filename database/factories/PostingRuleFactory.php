<?php

namespace Database\Factories;

use App\Models\PostingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostingRule>
 */
class PostingRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enabled' => true,
            'timezone' => 'Asia/Kolkata',
            'daily_limit' => 5,
            'window_start' => '08:00',
            'window_end' => '21:00',
            'min_interval_minutes' => 90,
            'slot_times' => ['08:30', '11:00', '13:30', '16:30', '20:00'],
            'active_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'auto_retry' => true,
            'max_attempts' => 5,
            'allow_reuse' => false,
            'selection_order' => PostingRule::ORDER_OLDEST,
        ];
    }
}
