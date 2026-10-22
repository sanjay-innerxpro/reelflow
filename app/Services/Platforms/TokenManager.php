<?php

namespace App\Services\Platforms;

use App\Enums\AccountStatus;
use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Services\Publishing\PublishException;
use Illuminate\Support\Facades\Cache;

/**
 * Hands out a usable access token for an account, refreshing Google tokens
 * when they are about to expire.
 */
class TokenManager
{
    public function __construct(private readonly GoogleClient $google) {}

    public function accessToken(SocialAccount $account): string
    {
        if ($account->status === AccountStatus::Disconnected || blank($account->access_token)) {
            throw PublishException::make(ErrorCategory::TokenExpired, 'Account is not connected.');
        }

        if ($account->platform === Platform::YouTube && $account->tokenExpiresSoon(5)) {
            return $this->refreshGoogle($account);
        }

        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            throw PublishException::make(ErrorCategory::TokenExpired, 'Access token expired; reconnect the account.');
        }

        return $account->access_token;
    }

    public function refreshGoogle(SocialAccount $account): string
    {
        if (blank($account->refresh_token)) {
            throw PublishException::make(ErrorCategory::TokenExpired, 'No refresh token stored; reconnect the YouTube channel.');
        }

        // Serialise refreshes so concurrent jobs do not race each other.
        return Cache::lock('google-refresh:'.$account->id, 30)->block(20, function () use ($account) {
            $account->refresh();
            if (! $account->tokenExpiresSoon(5)) {
                return $account->access_token;
            }

            try {
                $data = $this->google->refreshToken($account->refresh_token);
            } catch (PublishException $e) {
                if (in_array($e->httpStatus, [400, 401], true)) {
                    // invalid_grant: the user revoked access or the token expired.
                    throw $e->withCategory(ErrorCategory::TokenExpired, 'Google refused the refresh token (access revoked?).');
                }
                throw $e;
            }

            $account->forceFill([
                'access_token' => $data['access_token'],
                'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
                'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
                'last_authenticated_at' => now(),
            ])->save();

            return $account->access_token;
        });
    }
}
