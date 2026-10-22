<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform' => Platform::Facebook,
            'external_id' => (string) fake()->unique()->numberBetween(100000, 999999999),
            'name' => fake()->company(),
            'account_type' => 'facebook_page',
            'access_token' => 'EAAtest'.fake()->sha1(),
            'scopes' => ['pages_manage_posts', 'pages_read_engagement', 'instagram_content_publish', 'instagram_basic'],
            'status' => AccountStatus::Connected,
            'last_authenticated_at' => now(),
        ];
    }

    public function facebook(): static
    {
        return $this->state(['platform' => Platform::Facebook, 'account_type' => 'facebook_page']);
    }

    public function instagram(): static
    {
        return $this->state(['platform' => Platform::Instagram, 'account_type' => 'instagram_professional', 'username' => 'testgram']);
    }

    public function youtube(): static
    {
        return $this->state([
            'platform' => Platform::YouTube,
            'account_type' => 'youtube_channel',
            'access_token' => 'ya29.test-access',
            'refresh_token' => '1//test-refresh-token-value-abcdef',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'],
        ]);
    }
}
