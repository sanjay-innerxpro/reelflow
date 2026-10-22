<?php

namespace Tests\Support;

use App\Services\Media\MediaToolException;
use App\Services\Media\MediaToolkit;
use App\Services\Media\VideoMetadata;

class FakeMediaToolkit implements MediaToolkit
{
    public ?VideoMetadata $metadata = null;

    public bool $fail = false;

    /** @var array<int, string> */
    public array $calls = [];

    public function probe(string $path): VideoMetadata
    {
        $this->calls[] = 'probe';
        if ($this->fail) {
            throw new MediaToolException('ffprobe exploded');
        }

        return $this->metadata ?? new VideoMetadata(1080, 1920, 28.5, 30.0, 'h264', 'aac', true, true);
    }

    public function thumbnail(string $path, string $outputPath, float $atSecond, int $width = 480): void
    {
        $this->calls[] = 'thumbnail';
        file_put_contents($outputPath, 'jpeg-bytes');
    }

    public function frames(string $path, float $duration, int $count, int $width, string $outputDir): array
    {
        $this->calls[] = 'frames';
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $file = $outputDir.'/frame_'.$i.'.jpg';
            file_put_contents($file, 'frame-'.$i);
        }

        return $out;
    }

    public function extractAudio(string $path, string $outputPath, int $maxSeconds): void
    {
        $this->calls[] = 'audio';
        file_put_contents($outputPath, 'audio');
    }
}
