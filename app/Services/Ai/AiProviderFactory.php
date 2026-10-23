<?php

namespace App\Services\Ai;

use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\NullTranscriptionProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use App\Services\Ai\Providers\OpenAiTranscriptionProvider;

class AiProviderFactory
{
    /** @var array<string, AiVisionProvider> */
    private array $overrides = [];

    public function isConfigured(): bool
    {
        $provider = config('reelflow.ai.provider');
        if (isset($this->overrides[$provider])) {
            return true;
        }

        if ($provider === 'anthropic') {
            return filled($this->firstKey());
        }

        $preset = config("reelflow.ai.presets.{$provider}");
        if (! $preset) {
            return false;
        }

        return (! $preset['requires_key'] || filled($this->firstKey()))
            && filled(config('reelflow.ai.model') ?: $preset['model'])
            && filled(config('reelflow.ai.base_url') ?: $preset['base_url']);
    }

    /** The key the next request will use: AI_API_KEY, else the first spare. */
    private function firstKey(): ?string
    {
        return config('reelflow.ai.api_key') ?: (config('reelflow.ai.api_keys')[0] ?? null);
    }

    /**
     * Whether the configured provider has a free option (null = unknown).
     */
    public function isFree(): ?bool
    {
        $provider = config('reelflow.ai.provider');

        return match (true) {
            $provider === 'none' => true,
            $provider === 'anthropic' => false,
            default => config("reelflow.ai.presets.{$provider}.free"),
        };
    }

    public function vision(): AiVisionProvider
    {
        $provider = config('reelflow.ai.provider');
        if (isset($this->overrides[$provider])) {
            return $this->overrides[$provider];
        }

        $c = config('reelflow.ai');

        if ($provider === 'anthropic') {
            return new AnthropicProvider($c['api_key'], $c['model'], $c['timeout_seconds'], $c['base_url']);
        }

        $preset = $c['presets'][$provider] ?? null;
        if (! $preset) {
            throw new AiProviderException('No AI provider is configured (set AI_PROVIDER; free options: gemini, groq, ollama).');
        }

        return new OpenAiCompatibleProvider(
            apiKey: $c['api_key'],
            modelName: $c['model'] ?: $preset['model'],
            timeout: $c['timeout_seconds'],
            baseUrl: $c['base_url'] ?: $preset['base_url'],
            providerName: $provider,
            jsonMode: $c['json_mode'] ?: $preset['json_mode'],
            maxTokensField: $preset['max_tokens_field'],
            reasoningEffort: $c['reasoning_effort'] ?? null,
            apiKeys: $c['api_keys'] ?? [],
        );
    }

    public function transcription(): TranscriptionProvider
    {
        $c = config('reelflow.ai.transcription');

        $defaults = match ($c['provider']) {
            'groq' => ['https://api.groq.com/openai/v1', 'whisper-large-v3'],
            'openai' => ['https://api.openai.com/v1', 'whisper-1'],
            'compatible' => [null, null],
            default => null,
        };

        if ($defaults === null) {
            return new NullTranscriptionProvider;
        }

        $baseUrl = $c['base_url'] ?: $defaults[0];
        $model = $c['model'] ?: $defaults[1];
        if (! $baseUrl || ! $model) {
            return new NullTranscriptionProvider;
        }

        return new OpenAiTranscriptionProvider($c['api_key'] ?: $this->firstKey(), $model, $baseUrl, config('reelflow.ai.timeout_seconds'), $c['provider'] === 'compatible');
    }

    /**
     * Register a provider implementation under a name (used by tests and
     * for plugging in additional providers from a service provider).
     */
    public function extend(string $name, AiVisionProvider $provider): void
    {
        $this->overrides[$name] = $provider;
    }
}
