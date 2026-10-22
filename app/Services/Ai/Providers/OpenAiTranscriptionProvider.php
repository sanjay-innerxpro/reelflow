<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\TranscriptionProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible /audio/transcriptions (Whisper and compatible services).
 */
class OpenAiTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly bool $keyOptional = false,
    ) {}

    public function isEnabled(): bool
    {
        return $this->keyOptional || filled($this->apiKey);
    }

    public function transcribe(string $audioPath): ?string
    {
        try {
            $response = Http::withToken((string) ($this->apiKey ?: 'none'))
                ->timeout($this->timeout)
                ->attach('file', fopen($audioPath, 'rb'), basename($audioPath))
                ->post(rtrim($this->baseUrl, '/').'/audio/transcriptions', ['model' => $this->model, 'response_format' => 'json']);
        } catch (ConnectionException $e) {
            throw new AiProviderException('Transcription service unreachable: '.$e->getMessage(), true, $e);
        }

        if ($response->failed()) {
            throw new AiProviderException('Transcription failed (HTTP '.$response->status().')', $response->status() === 429 || $response->status() >= 500);
        }

        $text = trim((string) $response->json('text'));

        return $text !== '' ? $text : null;
    }
}
