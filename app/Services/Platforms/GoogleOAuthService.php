<?php

namespace App\Services\Platforms;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\PostingRule;
use App\Models\SocialAccount;
use App\Services\Publishing\PublishException;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Http;

/**
 * Google OAuth 2.0 (authorization code + PKCE, offline access) for YouTube.
 */
class GoogleOAuthService
{
    public function __construct(
        private readonly GoogleClient $google,
        private readonly ActivityLogger $activity,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('reelflow.google.client_id')) && filled(config('reelflow.google.client_secret')) && filled(config('reelflow.google.redirect_uri'));
    }

    public function authorizationUrl(string $state, string $codeVerifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return config('reelflow.google.auth_url').'?'.http_build_query([
            'client_id' => config('reelflow.google.client_id'),
            'redirect_uri' => config('reelflow.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('reelflow.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @return array<int, SocialAccount>
     */
    public function handleCallback(string $code, string $codeVerifier, ?int $userId): array
    {
        try {
            $tokens = $this->google->call(fn () => Http::asForm()->timeout(30)->post(config('reelflow.google.token_url'), [
                'code' => $code,
                'client_id' => config('reelflow.google.client_id'),
                'client_secret' => config('reelflow.google.client_secret'),
                'redirect_uri' => config('reelflow.google.redirect_uri'),
                'grant_type' => 'authorization_code',
                'code_verifier' => $codeVerifier,
            ]), 'Google code exchange')->json();

            $scopes = array_values(array_filter(explode(' ', (string) ($tokens['scope'] ?? ''))));
            if (! in_array('https://www.googleapis.com/auth/youtube.upload', $scopes, true)) {
                throw new OAuthException('The YouTube upload permission was not granted. Please allow it when connecting.');
            }

            $channels = $this->google->get('channels', $tokens['access_token'], ['part' => 'snippet,status', 'mine' => 'true'], 'YouTube channel lookup')['items'] ?? [];
        } catch (PublishException $e) {
            throw new OAuthException('Google authorisation failed: '.$e->getMessage(), 0, $e);
        }

        if ($channels === []) {
            throw new OAuthException('This Google account has no YouTube channel. Create a channel first, then connect again.');
        }

        $accounts = [];
        foreach ($channels as $channel) {
            $existing = SocialAccount::where('platform', Platform::YouTube->value)->where('external_id', $channel['id'])->first();

            $account = SocialAccount::updateOrCreate(
                ['platform' => Platform::YouTube->value, 'external_id' => $channel['id']],
                [
                    'user_id' => $userId,
                    'name' => $channel['snippet']['title'] ?? $channel['id'],
                    'username' => isset($channel['snippet']['customUrl']) ? ltrim($channel['snippet']['customUrl'], '@') : null,
                    'account_type' => 'youtube_channel',
                    'access_token' => $tokens['access_token'],
                    // Google only returns a refresh token on first consent; keep the old one otherwise.
                    'refresh_token' => $tokens['refresh_token'] ?? $existing?->refresh_token,
                    'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                    'scopes' => $scopes,
                    'status' => AccountStatus::Connected,
                    'status_reason' => ($channel['status']['longUploadsStatus'] ?? null) === 'disallowed' ? 'Channel is not verified for long uploads (Shorts are unaffected).' : null,
                    'last_authenticated_at' => now(),
                    'auth_problem_notified_at' => null,
                    'paused_until' => null,
                    'metadata' => [
                        'picture' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
                        'privacy_status' => $channel['status']['privacyStatus'] ?? null,
                        'long_uploads' => $channel['status']['longUploadsStatus'] ?? null,
                    ],
                ],
            );

            PostingRule::firstOrCreate(['social_account_id' => $account->id], PostingRule::defaultsFor($account));
            $this->activity->log('account.connected', $account, 'Connected YouTube channel '.$account->name);
            $accounts[] = $account;
        }

        return $accounts;
    }

    public function revoke(SocialAccount $account): void
    {
        $token = $account->refresh_token ?: $account->access_token;
        if (! $token) {
            return;
        }

        try {
            Http::asForm()->timeout(15)->post(config('reelflow.google.revoke_url'), ['token' => $token]);
        } catch (\Throwable) {
            // Best effort: the local tokens are deleted regardless.
        }
    }
}
