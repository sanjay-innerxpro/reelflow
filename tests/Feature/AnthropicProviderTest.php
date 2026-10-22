<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\CaptionPrompt;
use App\Services\Ai\Providers\AnthropicProvider;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private function provider(Response ...$responses): AnthropicProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $client = new Client(apiKey: 'sk-ant-test', requestOptions: ['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0]);

        return new AnthropicProvider('sk-ant-test', null, 30, null, $client);
    }

    private function message(string $text, string $stopReason = 'end_turn'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 2048, 'output_tokens' => 512],
        ]));
    }

    public function test_sends_frames_and_schema_and_reads_usage(): void
    {
        $frame = tempnam(sys_get_temp_dir(), 'frame');
        file_put_contents($frame, 'jpeg-bytes');

        $response = $this->provider($this->message(FakeAiProvider::validJson()))
            ->generate(new AiRequest('system prompt', 'user prompt', [$frame], CaptionPrompt::schema(), 2000));
        @unlink($frame);

        $this->assertSame(2048, $response->inputTokens);
        $this->assertSame(512, $response->outputTokens);
        $this->assertSame('claude-opus-5-5', $response->model);
        $this->assertStringContainsString('Masala', $response->text);

        $request = $this->history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringEndsWith('/v1/messages', $request->getUri()->getPath());
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('system prompt', $body['system']);
        $this->assertSame('image', $body['messages'][0]['content'][0]['type']);
        $this->assertSame('image/jpeg', $body['messages'][0]['content'][0]['source']['media_type']);
        $this->assertSame(base64_encode('jpeg-bytes'), $body['messages'][0]['content'][0]['source']['data']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame('default', $body['fallbacks']);
    }

    public function test_refusal_is_not_treated_as_success(): void
    {
        $this->expectException(AiProviderException::class);
        $this->provider($this->message('', 'refusal'))->generate(new AiRequest('s', 'u', [], CaptionPrompt::schema(), 100));
    }

    public function test_rate_limit_is_retryable(): void
    {
        try {
            $this->provider(new Response(429, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow']])))
                ->generate(new AiRequest('s', 'u', [], CaptionPrompt::schema(), 100));
            $this->fail('Expected exception');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->retryable);
        }
    }
}
