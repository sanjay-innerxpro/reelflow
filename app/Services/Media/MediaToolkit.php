<?php

namespace App\Services\Media;

interface MediaToolkit
{
    public function probe(string $path): VideoMetadata;

    public function thumbnail(string $path, string $outputPath, float $atSecond, int $width = 480): void;

    /**
     * Extract evenly spaced JPEG frames; returns their file paths.
     *
     * @return array<int, string>
     */
    public function frames(string $path, float $duration, int $count, int $width, string $outputDir): array;

    /**
     * Extract a mono, low-bitrate audio track for speech-to-text.
     */
    public function extractAudio(string $path, string $outputPath, int $maxSeconds): void;
}
