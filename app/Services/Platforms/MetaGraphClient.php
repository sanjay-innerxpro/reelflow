<?php

namespace App\Services\Platforms;

use App\Enums\ErrorCategory;
use App\Services\Publishing\MetaErrorClassifier;
use App\Services\Publishing\PublishException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Thin wrapper around the Meta Graph API. Access tokens are sent in the
 * Authorization header (never in URLs) so they do not end up in logs.
 */
class MetaGraphClient
{
    public function url(string $path): string
    {
        return rtrim(config('reelflow.meta.graph_url'), '/').'/'.config('reelflow.meta.graph_version').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, string $token, array $query = [], string $context = 'Graph GET'): array
    {
        return $this->send(fn () => $this->request($token)->get($this->url($path), $query), $context);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $path, string $token, array $data = [], string $context = 'Graph POST'): array
    {
        return $this->send(fn () => $this->request($token)->asForm()->post($this->url($path), $data), $context);
    }

    /**
     * Binary upload to rupload.facebook.com (Reels and Instagram resumable uploads).
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function rupload(string $url, string $token, StreamInterface $body, array $headers, string $context): array
    {
        return $this->send(fn () => Http::withHeaders(array_merge(['Authorization' => 'OAuth '.$token], $headers))
            ->timeout(config('reelflow.publishing.http_timeout_seconds'))
            ->withBody($body, 'application/octet-stream')
            ->post($url), $context);
    }

    public function ruploadUrl(string $kind, string $id): string
    {
        // kind: "video-upload" (Facebook Reels) or "ig-api-upload" (Instagram)
        return rtrim(config('reelflow.meta.rupload_url'), '/').'/'.$kind.'/'.config('reelflow.meta.graph_version').'/'.$id;
    }

    /**
     * App-token call (no user token), e.g. for /debug_token and OAuth exchange.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function appGet(string $path, array $query, string $context): array
    {
        return $this->send(fn () => Http::acceptJson()->timeout(30)->get($this->url($path), $query), $context);
    }

    public function appToken(): string
    {
        return config('reelflow.meta.app_id').'|'.config('reelflow.meta.app_secret');
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(60);
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call, string $context): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new PublishException(ErrorCategory::Network, "{$context}: connection failed ({$e->getMessage()})", previous: $e);
        }

        if ($response->failed() || $response->json('error') !== null) {
            throw MetaErrorClassifier::fromResponse($response, $context);
        }

        return (array) $response->json();
    }
}
