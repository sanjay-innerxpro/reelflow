<?php

namespace App\Services\Ai;

use App\Enums\AiStatus;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Enums\VideoStatus;
use App\Models\AiContent;
use App\Models\AiGeneration;
use App\Models\Video;
use App\Services\Media\MediaToolkit;
use App\Services\Storage\VideoStorage;
use App\Support\ActivityLogger;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiCaptionService
{
    public function __construct(
        private readonly AiProviderFactory $providers,
        private readonly CaptionPrompt $prompt,
        private readonly CaptionValidator $validator,
        private readonly MediaToolkit $media,
        private readonly VideoStorage $storage,
        private readonly Settings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Run (or resume) one generation. The generation ID makes retries
     * idempotent: a finished generation is returned as-is and its content
     * rows are keyed by (generation, platform).
     */
    public function generate(Video $video, string $generationId): AiGeneration
    {
        $existing = AiGeneration::find($generationId);
        if ($existing && in_array($existing->status, [AiGeneration::STATUS_SUCCEEDED, AiGeneration::STATUS_LOW_CONFIDENCE], true)) {
            return $existing;
        }

        $provider = $this->providers->vision();
        [$systemPrompt, $promptVersion] = $this->prompt->system();

        // forceFill: the primary key is not mass assignable, and the job's
        // generation ID must be kept so retries reuse this record.
        $generation = $existing ?? new AiGeneration;
        $generation->forceFill([
            'id' => $generationId,
            'video_id' => $video->id,
            'status' => AiGeneration::STATUS_RUNNING,
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'prompt_version' => $promptVersion,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $video->update(['ai_status' => AiStatus::Generating]);

        $this->assertWithinLimits($video);

        $workDir = storage_path('app/tmp/ai-'.$generationId);
        File::ensureDirectoryExists($workDir);
        $local = null;

        try {
            $local = $this->storage->downloadToTemp($video);
            $frames = $this->media->frames(
                $local,
                (float) $video->duration_seconds,
                config('reelflow.ai.max_frames'),
                config('reelflow.ai.frame_width'),
                $workDir,
            );
            if ($frames === []) {
                throw new AiProviderException('No frames could be extracted from the video.');
            }

            $transcript = $this->transcribe($video, $local, $workDir);

            $started = microtime(true);
            $response = $provider->generate(new AiRequest(
                systemPrompt: $systemPrompt,
                userPrompt: $this->prompt->user($video, count($frames), $transcript),
                imagePaths: $frames,
                jsonSchema: CaptionPrompt::schema(),
                maxOutputTokens: config('reelflow.ai.max_output_tokens'),
            ));
            $durationMs = (int) ((microtime(true) - $started) * 1000);

            $generation->fill([
                'model' => $response->model,
                'frame_count' => count($frames),
                'used_transcript' => $transcript !== null,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_usd' => $this->estimateCost($response),
                'duration_ms' => $durationMs,
            ])->save();

            $result = $this->validator->parse($response->text);
        } finally {
            if ($local) {
                @unlink($local);
            }
            File::deleteDirectory($workDir);
        }

        return $this->persist($video, $generation, $result);
    }

    private function persist(Video $video, AiGeneration $generation, CaptionResult $result): AiGeneration
    {
        $low = $result->isLowConfidence();
        $status = $low ? ContentStatus::LowConfidence : ContentStatus::Valid;
        $warnings = array_values(array_merge($result->contentWarnings, $result->adjustments));

        DB::transaction(function () use ($video, $generation, $result, $low, $status, $warnings) {
            $video->aiContents()->where('ai_generation_id', '!=', $generation->id)->update(['is_current' => false]);

            $common = [
                'video_id' => $video->id,
                'language' => $result->language,
                'provider' => $generation->provider,
                'model' => $generation->model,
                'prompt_version' => $generation->prompt_version,
                'confidence' => $result->confidence,
                'validation_status' => $status,
                'content_warnings' => $warnings ?: null,
                'is_current' => true,
                'generated_at' => now(),
            ];

            $rows = [
                Platform::Facebook->value => ['caption' => $result->facebookCaption, 'hashtags' => $result->facebookHashtags],
                Platform::Instagram->value => ['caption' => $result->instagramCaption, 'hashtags' => $result->instagramHashtags],
                Platform::YouTube->value => [
                    'title' => $result->youtubeTitle,
                    'description' => $result->youtubeDescription,
                    'caption' => $result->youtubeDescription,
                    'hashtags' => $result->youtubeHashtags,
                    'tags' => $result->youtubeTags,
                ],
            ];

            foreach ($rows as $platform => $fields) {
                AiContent::updateOrCreate(
                    ['ai_generation_id' => $generation->id, 'platform' => $platform],
                    array_merge($common, $fields),
                );
            }

            $generation->update([
                'status' => $low ? AiGeneration::STATUS_LOW_CONFIDENCE : AiGeneration::STATUS_SUCCEEDED,
                'confidence' => $result->confidence,
                'result' => $result->toArray(),
                'completed_at' => now(),
            ]);

            $video->fill([
                'language' => mb_substr($result->language, 0, 20),
                'topic' => $result->topic ? mb_substr($result->topic, 0, 255) : null,
                'category' => $result->category ? mb_substr($result->category, 0, 100) : $video->category,
                'ai_confidence' => $result->confidence,
                'ai_status' => $low ? AiStatus::LowConfidence : AiStatus::Completed,
                'analyzed_at' => now(),
            ])->save();

            if ($video->status === VideoStatus::Processing) {
                $video->transitionTo(VideoStatus::Ready, [], 'Captions generated');
            }
        });

        $this->activity->log('ai.generated', $video, sprintf(
            'Captions generated by %s/%s (confidence %.2f)%s',
            $generation->provider, $generation->model, $result->confidence, $low ? ' — needs review' : '',
        ));

        return $generation->refresh();
    }

    /**
     * Record a failed attempt. When final, the video stays usable: it is
     * marked ready with ai_status=failed so captions can be written by hand.
     */
    public function markFailed(Video $video, string $generationId, string $error, bool $final): void
    {
        AiGeneration::whereKey($generationId)->update([
            'status' => AiGeneration::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
            'completed_at' => now(),
        ]);

        if (! $final) {
            return;
        }

        $video->refresh();
        $video->update(['ai_status' => AiStatus::Failed]);
        if ($video->status === VideoStatus::Processing) {
            $video->transitionTo(VideoStatus::Ready, [], 'Ready without AI captions: '.$error);
        }
        $this->activity->log('ai.failed', $video, $error, level: 'warning');
    }

    private function assertWithinLimits(Video $video): void
    {
        $maxBytes = config('reelflow.ai.max_video_size_mb') * 1024 * 1024;
        if ($video->size_bytes > $maxBytes) {
            throw new AiProviderException('Video exceeds AI_MAX_VIDEO_SIZE_MB; write captions manually.');
        }

        $budget = config('reelflow.ai.daily_budget_usd');
        if ($budget !== null) {
            $spent = (float) AiGeneration::where('created_at', '>=', now()->startOfDay())->sum('cost_usd');
            if ($spent >= $budget) {
                throw new AiBudgetExceeded(sprintf('Daily AI budget of $%.2f reached ($%.2f spent).', $budget, $spent));
            }
        }
    }

    private function transcribe(Video $video, string $local, string $workDir): ?string
    {
        if (! $this->settings->bool('ai.transcribe_audio') || ! $video->has_audio) {
            return null;
        }

        $provider = $this->providers->transcription();
        if (! $provider->isEnabled()) {
            return null;
        }

        try {
            $audio = $workDir.'/audio.mp3';
            $this->media->extractAudio($local, $audio, config('reelflow.ai.transcription.max_audio_seconds'));

            return $provider->transcribe($audio);
        } catch (Throwable $e) {
            // Transcription is optional context; caption from frames alone.
            Log::warning('Transcription skipped', ['video_id' => $video->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function estimateCost(AiResponse $response): ?float
    {
        $in = config('reelflow.ai.input_cost_per_mtok');
        $out = config('reelflow.ai.output_cost_per_mtok');
        if ($in === null || $out === null || $response->inputTokens === null || $response->outputTokens === null) {
            return null;
        }

        return round(($response->inputTokens * $in + $response->outputTokens * $out) / 1_000_000, 6);
    }
}
