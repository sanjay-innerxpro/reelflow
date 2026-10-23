<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use App\Services\Ai\AiVisionProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Any provider exposing the OpenAI-style /chat/completions endpoint with
 * image input: free tiers (Google Gemini, Groq), self-hosted Ollama, or
 * paid OpenAI.
 */
class OpenAiCompatibleProvider implements AiVisionProvider
{
    private const JSON_MODES = ['json_schema', 'json_object', 'none'];

    public function __construct(
        private readonly ?string $apiKey,
        private readonly ?string $modelName,
        private readonly int $timeout,
        private readonly ?string $baseUrl = null,
        private readonly string $providerName = 'openai',
        private readonly string $jsonMode = 'json_schema',
        private readonly string $maxTokensField = 'max_completion_tokens',
        private readonly ?string $reasoningEffort = null,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        if (! $this->modelName) {
            throw new AiProviderException("AI_MODEL must be set for the {$this->providerName} provider.");
        }

        return $this->modelName;
    }

    public function generate(AiRequest $request): AiResponse
    {
        if (! $this->baseUrl) {
            throw new AiProviderException("AI_BASE_URL must be set for the {$this->providerName} provider.");
        }

        $content = [['type' => 'text', 'text' => $request->userPrompt]];
        foreach ($request->imagePaths as $path) {
            $image = ['url' => 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($path))];
            if ($this->providerName === 'openai') {
                $image['detail'] = 'low';
            }
            $content[] = ['type' => 'image_url', 'image_url' => $image];
        }

        $systemPrompt = $request->systemPrompt;
        $modes = array_slice(self::JSON_MODES, max(0, (int) array_search($this->jsonMode, self::JSON_MODES, true)));

        foreach ($modes as $i => $mode) {
            $payload = [
                'model' => $this->model(),
                $this->maxTokensField => $request->maxOutputTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $mode === 'json_schema' ? $systemPrompt : $systemPrompt."\n\nThe JSON must match this JSON Schema exactly:\n".json_encode($request->jsonSchema)],
                    ['role' => 'user', 'content' => $content],
                ],
            ];
            if ($this->reasoningEffort) {
                $payload['reasoning_effort'] = $this->reasoningEffort;
            }
            if ($mode === 'json_schema') {
                $payload['response_format'] = [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'caption_result', 'schema' => $request->jsonSchema, 'strict' => true],
                ];
            } elseif ($mode === 'json_object') {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $response = $this->send($payload);

            // Some endpoints do not support every JSON mode: degrade one step
            // and try again. The output is validated against the schema anyway.
            if ($response->status() === 400 && $mode !== 'none' && $i < count($modes) - 1 && $this->rejectsResponseFormat($response)) {
                continue;
            }

            return $this->parse($response);
        }

        throw new AiProviderException('The AI endpoint rejected every JSON output mode.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): Response
    {
        try {
            return Http::withToken((string) ($this->apiKey ?: 'none'))
                ->timeout($this->timeout)
                ->acceptJson()
                ->post(rtrim((string) $this->baseUrl, '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new AiProviderException("Could not reach the {$this->providerName} AI endpoint: ".$e->getMessage(), true, $e);
        }
    }

    private function rejectsResponseFormat(Response $response): bool
    {
        $message = strtolower((string) ($response->json('error.message') ?? $response->json('0.error.message') ?? $response->body()));

        return str_contains($message, 'response_format') || str_contains($message, 'json_schema')
            || str_contains($message, 'response format') || str_contains($message, 'json mode') || str_contains($message, 'schema');
    }

    private function parse(Response $response): AiResponse
    {
        if ($response->failed()) {
            $status = $response->status();
            $message = $response->json('error.message') ?? $response->json('0.error.message') ?? 'HTTP '.$status;
            $hint = $status === 429 ? ' (free-tier rate limit or daily quota reached; it will be retried)' : '';
            throw new AiProviderException("AI provider error ({$status}): {$message}{$hint}", $status === 429 || $status >= 500);
        }

        $choice = $response->json('choices.0');
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new AiProviderException('The model response was cut off (raise AI_MAX_OUTPUT_TOKENS).');
        }
        if (! empty($choice['message']['refusal'])) {
            throw new AiProviderException('The model declined to analyse this video.');
        }

        return new AiResponse(
            text: (string) ($choice['message']['content'] ?? ''),
            model: (string) ($response->json('model') ?? $this->model()),
            inputTokens: $response->json('usage.prompt_tokens'),
            outputTokens: $response->json('usage.completion_tokens'),
            stopReason: $choice['finish_reason'] ?? null,
        );
    }
}
