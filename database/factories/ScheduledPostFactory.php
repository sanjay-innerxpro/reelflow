<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Enums\PublishStage;
use App\Models\ScheduledPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledPost>
 */
class ScheduledPostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scheduled_at' => now()->subMinute(),
            'status' => PostStatus::Scheduled,
            'stage' => PublishStage::Pending,
            'max_attempts' => 5,
        ];
    }
}
