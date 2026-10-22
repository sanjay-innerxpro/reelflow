<?php

namespace App\Services\Media;

final class VideoMetadata
{
    public function __construct(
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?float $duration,
        public readonly ?float $frameRate,
        public readonly ?string $videoCodec,
        public readonly ?string $audioCodec,
        public readonly bool $hasVideo,
        public readonly bool $hasAudio,
        public readonly ?string $formatName = null,
        public readonly ?int $bitRate = null,
    ) {}

    /**
     * Parse `ffprobe -print_format json -show_format -show_streams` output.
     *
     * @param  array<string, mixed>  $probe
     */
    public static function fromFfprobe(array $probe): self
    {
        $streams = collect($probe['streams'] ?? []);
        $video = $streams->first(fn ($s) => ($s['codec_type'] ?? null) === 'video' && ! (($s['disposition']['attached_pic'] ?? 0) === 1));
        $audio = $streams->first(fn ($s) => ($s['codec_type'] ?? null) === 'audio');

        $width = isset($video['width']) ? (int) $video['width'] : null;
        $height = isset($video['height']) ? (int) $video['height'] : null;

        // Phones store portrait video as landscape pixels plus a rotation flag.
        $rotation = (int) ($video['tags']['rotate'] ?? 0);
        foreach ($video['side_data_list'] ?? [] as $side) {
            if (isset($side['rotation'])) {
                $rotation = (int) $side['rotation'];
            }
        }
        if ($width && $height && abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = $probe['format']['duration'] ?? $video['duration'] ?? null;

        return new self(
            width: $width,
            height: $height,
            duration: $duration !== null ? round((float) $duration, 3) : null,
            frameRate: self::parseRate($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? null),
            videoCodec: $video['codec_name'] ?? null,
            audioCodec: $audio['codec_name'] ?? null,
            hasVideo: $video !== null,
            hasAudio: $audio !== null,
            formatName: $probe['format']['format_name'] ?? null,
            bitRate: isset($probe['format']['bit_rate']) ? (int) $probe['format']['bit_rate'] : null,
        );
    }

    private static function parseRate(?string $rate): ?float
    {
        if (! $rate || $rate === '0/0') {
            return null;
        }
        if (str_contains($rate, '/')) {
            [$n, $d] = array_map('floatval', explode('/', $rate, 2));

            return $d > 0 ? round($n / $d, 3) : null;
        }

        return round((float) $rate, 3);
    }
}
