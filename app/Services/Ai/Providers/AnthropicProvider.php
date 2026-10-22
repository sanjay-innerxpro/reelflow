<?php

namespace App\Services\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use App\Services\Ai\AiVisionProvider;

/**
 * Claude via the official Anthropic PHP SDK, with structured JSON output.
 */
class AnthropicProvider implements AiVisionProvider
{
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    private ?Client $client;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly ?string $modelName,
        private readonly int $timeout,
        private readonly ?string $baseUrl = null,
        ?Client $client = null,
    ) {
        $this->client = $client;
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->modelName ?: self::DEFAULT_MODEL;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $content = [];
        foreach ($request->imagePaths as $path) {
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode((string) file_get_contents($path))],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $request->userPrompt];

        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: $request->maxOutputTokens,
                messages: [['role' => 'user', 'content' => $content]],
                model: $this->model(),
                system: $request->systemPrompt,
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => $request->jsonSchema],
                ],
                // On a policy decline, let the API retry on the model's default fallback.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (RateLimitException $e) {
            throw new AiProviderException('Anthropic rate limit: '.$e->getMessage(), true, $e);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new AiProviderException('Anthropic authentication failed: check AI_API_KEY.', false, $e);
        } catch (BadRequestException $e) {
            throw new AiProviderException('Anthropic rejected the request: '.$e->getMessage(), false, $e);
        } catch (APIStatusException $e) {
            throw new AiProviderException('Anthropic API error: '.$e->getMessage(), true, $e);
        } catch (APIConnectionException $e) {
            throw new AiProviderException('Could not reach the Anthropic API: '.$e->getMessage(), true, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiProviderException('The model declined to analyse this video.', false);
        }
        if ($message->stopReason === 'max_tokens') {
            throw new AiProviderException('The model response was cut off (raise AI_MAX_OUTPUT_TOKENS).', false);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new AiResponse(
            text: $text,
            model: (string) $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            stopReason: $message->stopReason,
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl ?: null,
            requestOptions: ['timeout' => (float) $this->timeout, 'maxRetries' => 2],
        );
    }
}
