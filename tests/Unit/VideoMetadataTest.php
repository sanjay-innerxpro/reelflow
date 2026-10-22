<?php

namespace Tests\Unit;

use App\Services\Media\VideoMetadata;
use PHPUnit\Framework\TestCase;

class VideoMetadataTest extends TestCase
{
    public function test_parses_ffprobe_output(): void
    {
        $meta = VideoMetadata::fromFfprobe([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1080, 'height' => 1920, 'avg_frame_rate' => '30000/1001'],
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
            'format' => ['duration' => '42.123456', 'format_name' => 'mov,mp4', 'bit_rate' => '5000000'],
        ]);

        $this->assertSame(1080, $meta->width);
        $this->assertSame(1920, $meta->height);
        $this->assertSame(42.123, $meta->duration);
        $this->assertSame(29.97, $meta->frameRate);
        $this->assertTrue($meta->hasAudio);
        $this->assertSame('h264', $meta->videoCodec);
    }

    public function test_rotation_swaps_dimensions(): void
    {
        $meta = VideoMetadata::fromFfprobe([
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'side_data_list' => [['rotation' => -90]]]],
            'format' => ['duration' => '10'],
        ]);

        $this->assertSame(1080, $meta->width);
        $this->assertSame(1920, $meta->height);
        $this->assertFalse($meta->hasAudio);
    }

    public function test_cover_art_is_not_a_video_stream(): void
    {
        $meta = VideoMetadata::fromFfprobe([
            'streams' => [['codec_type' => 'video', 'width' => 300, 'height' => 300, 'disposition' => ['attached_pic' => 1]]],
            'format' => ['duration' => '10'],
        ]);

        $this->assertFalse($meta->hasVideo);
    }
}
