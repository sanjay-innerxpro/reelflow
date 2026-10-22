<?php

namespace Database\Factories;

use App\Enums\AiStatus;
use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    public function definition(): array
    {
        $checksum = hash('sha256', fake()->uuid());

        return [
            'original_filename' => fake()->slug(3).'.mp4',
            'folder' => 'Cooking/Recipes',
            'checksum_sha256' => $checksum,
            'storage_disk' => 'videos',
            'storage_key' => 'originals/'.substr($checksum, 0, 2).'/'.$checksum.'.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 5_000_000,
            'width' => 1080,
            'height' => 1920,
            'duration_seconds' => 30.0,
            'frame_rate' => 30,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'has_audio' => true,
            'status' => VideoStatus::Ready,
            'ai_status' => AiStatus::Completed,
            'uploaded_at' => now()->subHour(),
            'processed_at' => now()->subHour(),
        ];
    }

    public function discovered(): static
    {
        return $this->state(['status' => VideoStatus::Discovered, 'ai_status' => AiStatus::Pending, 'storage_key' => null, 'uploaded_at' => null, 'width' => null, 'height' => null, 'duration_seconds' => null]);
    }

    public function uploaded(): static
    {
        return $this->state(['status' => VideoStatus::Uploaded, 'ai_status' => AiStatus::Pending, 'width' => null, 'height' => null, 'duration_seconds' => null, 'processed_at' => null]);
    }
}
