<?php

namespace App\Services\Platforms;

use App\Enums\ErrorCategory;
use App\Services\Publishing\GoogleErrorClassifier;
use App\Services\Publishing\PublishException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleClient
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, string $token, array $query, string $context): array
    {
        $response = $this->call(fn () => Http::withToken($token)->acceptJson()->timeout(60)
            ->get(rtrim(config('reelflow.google.api_url'), '/').'/'.ltrim($path, '/'), $query), $context);

        return (array) $response->json();
    }

    /**
     * @param  callable(): Response  $call
     */
    public function call(callable $call, string $context): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new PublishException(ErrorCategory::Network, "{$context}: connection failed ({$e->getMessage()})", previous: $e);
        }

        if ($response->failed() && $response->status() !== 308) {
            throw GoogleErrorClassifier::fromResponse($response, $context);
        }

        return $response;
    }

    /**
     * @return array{access_token: string, expires_in: int, refresh_token?: string, scope?: string}
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = $this->call(fn () => Http::asForm()->timeout(30)->post(config('reelflow.google.token_url'), [
            'client_id' => config('reelflow.google.client_id'),
            'client_secret' => config('reelflow.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]), 'Google token refresh');

        return (array) $response->json();
    }
}
