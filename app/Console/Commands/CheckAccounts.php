<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Services\Platforms\GoogleClient;
use App\Services\Platforms\MetaOAuthService;
use App\Services\Platforms\TokenManager;
use App\Services\Publishing\AccountHealthService;
use App\Services\Publishing\PublishException;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

class CheckAccounts extends Command
{
    protected $signature = 'reels:check-accounts';

    protected $description = 'Verify stored platform tokens and flag revoked or expired ones';

    public function handle(MetaOAuthService $meta, TokenManager $tokens, AccountHealthService $health): int
    {
        Tenant::each(fn () => SocialAccount::where('status', AccountStatus::Connected->value)->get()->each(function (SocialAccount $account) use ($meta, $tokens, $health) {
            try {
                if ($account->platform === Platform::YouTube) {
                    // Cheap authenticated call; refreshes the token when needed.
                    app(GoogleClient::class)->get('channels', $tokens->accessToken($account), ['part' => 'id', 'mine' => 'true'], 'YouTube token check');
                } elseif ($meta->isConfigured() && ! $meta->checkToken($account)) {
                    $health->flag($account, ErrorCategory::TokenExpired, 'Meta reports the access token is no longer valid.');
                }
                $this->line("✓ {$account->platform->value} {$account->name}");
            } catch (PublishException $e) {
                if ($e->category->pausesAccount()) {
                    $health->flag($account, $e->category, $e->getMessage());
                }
                $this->warn("✗ {$account->platform->value} {$account->name}: {$e->getMessage()}");
            }
        }));

        return self::SUCCESS;
    }
}
