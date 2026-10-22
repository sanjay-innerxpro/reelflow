<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\AiContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiContent>
 */
class AiContentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform' => Platform::Facebook,
            'title' => 'Quick masala pasta',
            'caption' => 'Ten-minute masala pasta with pantry spices.',
            'description' => 'Ten-minute masala pasta with pantry spices.',
            'hashtags' => ['masalapasta', 'quickrecipes'],
            'tags' => ['masala pasta', 'quick recipe'],
            'language' => 'en',
            'provider' => 'test',
            'model' => 'test-model',
            'prompt_version' => 'v1',
            'confidence' => 0.9,
            'validation_status' => ContentStatus::Valid,
            'is_current' => true,
            'generated_at' => now(),
        ];
    }
}
