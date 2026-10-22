<?php

namespace App\Services\Media;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class FfmpegMediaToolkit implements MediaToolkit
{
    public function probe(string $path): VideoMetadata
    {
        $output = $this->run([
            config('reelflow.media.ffprobe_path'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

        $json = json_decode($output, true);
        if (! is_array($json)) {
            throw new MediaToolException('ffprobe returned unreadable output.');
        }

        return VideoMetadata::fromFfprobe($json);
    }

    public function thumbnail(string $path, string $outputPath, float $atSecond, int $width = 480): void
    {
        $this->run([
            config('reelflow.media.ffmpeg_path'), '-y', '-v', 'error', '-ss', (string) max(0, $atSecond), '-i', $path,
            '-frames:v', '1', '-vf', "scale={$width}:-2", '-q:v', '3', $outputPath,
        ]);

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new MediaToolException('ffmpeg did not produce a thumbnail.');
        }
    }

    public function frames(string $path, float $duration, int $count, int $width, string $outputDir): array
    {
        $count = max(1, $count);
        $paths = [];

        for ($i = 0; $i < $count; $i++) {
            // Sample the middle of each equal segment, avoiding black first/last frames.
            $at = $duration > 0 ? ($duration / $count) * ($i + 0.5) : 0;
            $out = $outputDir.'/frame_'.$i.'.jpg';
            $this->run([
                config('reelflow.media.ffmpeg_path'), '-y', '-v', 'error', '-ss', sprintf('%.2f', $at), '-i', $path,
                '-frames:v', '1', '-vf', "scale={$width}:-2", '-q:v', '5', $out,
            ]);
            if (is_file($out) && filesize($out) > 0) {
                $paths[] = $out;
            }
        }

        return $paths;
    }

    public function extractAudio(string $path, string $outputPath, int $maxSeconds): void
    {
        $this->run([
            config('reelflow.media.ffmpeg_path'), '-y', '-v', 'error', '-i', $path, '-t', (string) $maxSeconds,
            '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k', $outputPath,
        ]);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(config('reelflow.media.timeout_seconds'));

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new MediaToolException(trim($process->getErrorOutput()) ?: $e->getMessage(), 0, $e);
        } catch (RuntimeException $e) {
            throw new MediaToolException('Could not run '.basename($command[0]).': '.$e->getMessage(), 0, $e);
        }

        return $process->getOutput();
    }
}
