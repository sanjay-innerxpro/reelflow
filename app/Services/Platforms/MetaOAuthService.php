<?php

namespace App\Services\Platforms;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\PostingRule;
use App\Models\SocialAccount;
use App\Services\Publishing\PublishException;
use App\Support\ActivityLogger;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Facebook Login for Business → Pages → linked Instagram Professional accounts.
 */
class MetaOAuthService
{
    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly ActivityLogger $activity,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('reelflow.meta.app_id')) && filled(config('reelflow.meta.app_secret')) && filled(config('reelflow.meta.redirect_uri'));
    }

    public function authorizationUrl(string $state): string
    {
        $params = [
            'client_id' => config('reelflow.meta.app_id'),
            'redirect_uri' => config('reelflow.meta.redirect_uri'),
            'state' => $state,
            'response_type' => 'code',
        ];

        if ($configId = config('reelflow.meta.config_id')) {
            // Login for Business: permissions come from the saved configuration.
            $params['config_id'] = $configId;
            $params['override_default_response_type'] = 'true';
        } else {
            $params['scope'] = implode(',', config('reelflow.meta.scopes'));
        }

        return rtrim(config('reelflow.meta.dialog_url'), '/').'/'.config('reelflow.meta.graph_version').'/dialog/oauth?'.http_build_query($params);
    }

    /**
     * Exchange the code for a long-lived user token and stash the Pages the
     * user can pick from. Returns a short-lived selection key.
     */
    public function handleCallback(string $code): string
    {
        try {
            $short = $this->graph->appGet('oauth/access_token', [
                'client_id' => config('reelflow.meta.app_id'),
                'client_secret' => config('reelflow.meta.app_secret'),
                'redirect_uri' => config('reelflow.meta.redirect_uri'),
                'code' => $code,
            ], 'Meta code exchange');

            $long = $this->graph->appGet('oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('reelflow.meta.app_id'),
                'client_secret' => config('reelflow.meta.app_secret'),
                'fb_exchange_token' => $short['access_token'] ?? '',
            ], 'Meta long-lived token');

            $userToken = (string) ($long['access_token'] ?? '');
            $granted = collect($this->graph->get('me/permissions', $userToken, [], 'Meta permissions')['data'] ?? [])
                ->where('status', 'granted')->pluck('permission')->values()->all();

            $pages = $this->graph->get('me/accounts', $userToken, [
                'fields' => 'id,name,access_token,category,tasks,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
                'limit' => 100,
            ], 'Meta pages')['data'] ?? [];
        } catch (PublishException $e) {
            throw new OAuthException('Meta authorisation failed: '.$e->getMessage(), 0, $e);
        }

        if ($pages === []) {
            throw new OAuthException('No Facebook Pages were shared with ReelFlow. Reconnect and select at least one Page.');
        }

        $key = Str::random(40);
        Cache::put($this->pendingKey($key), Crypt::encrypt(['pages' => $pages, 'scopes' => $granted]), now()->addMinutes(15));

        return $key;
    }

    /**
     * Page list for the selection screen (no tokens).
     *
     * @return array{pages: array<int, array<string, mixed>>, scopes: array<int, string>}
     */
    public function pendingSelection(string $key): array
    {
        $data = $this->pending($key);

        return [
            'scopes' => $data['scopes'],
            'pages' => array_map(fn ($p) => [
                'id' => $p['id'],
                'name' => $p['name'],
                'category' => $p['category'] ?? null,
                'can_publish' => in_array('CREATE_CONTENT', $p['tasks'] ?? [], true) || ! isset($p['tasks']),
                'instagram' => isset($p['instagram_business_account']['id']) ? [
                    'id' => $p['instagram_business_account']['id'],
                    'username' => $p['instagram_business_account']['username'] ?? null,
                    'name' => $p['instagram_business_account']['name'] ?? null,
                ] : null,
            ], $data['pages']),
        ];
    }

    /**
     * @param  array<int, string>  $pageIds
     * @param  array<int, string>  $instagramIds
     * @return array<int, SocialAccount>
     */
    public function connect(string $key, array $pageIds, array $instagramIds, ?int $userId): array
    {
        $data = $this->pending($key);
        $scopes = $data['scopes'];
        $connected = [];
        $this->assertWithinAccountLimit($data['pages'], $pageIds, $instagramIds);

        DB::transaction(function () use ($data, $pageIds, $instagramIds, $userId, $scopes, &$connected) {
            foreach ($data['pages'] as $page) {
                $ig = $page['instagram_business_account'] ?? null;
                $wantPage = in_array($page['id'], $pageIds, true);
                $wantIg = $ig && in_array($ig['id'], $instagramIds, true);
                if (! $wantPage && ! $wantIg) {
                    continue;
                }

                $canPublish = in_array('CREATE_CONTENT', $page['tasks'] ?? [], true) || ! isset($page['tasks']);
                $pageAccount = null;

                if ($wantPage) {
                    $pageAccount = $this->upsert($userId, Platform::Facebook, $page['id'], [
                        'name' => $page['name'],
                        'account_type' => 'facebook_page',
                        'access_token' => $page['access_token'],
                        'token_expires_at' => null,
                        'scopes' => $scopes,
                        'status' => $canPublish ? AccountStatus::Connected : AccountStatus::Ineligible,
                        'status_reason' => $canPublish ? null : 'Your Page role does not allow creating content.',
                        'metadata' => ['category' => $page['category'] ?? null, 'tasks' => $page['tasks'] ?? null, 'picture' => $page['picture']['data']['url'] ?? null],
                    ]);
                    $connected[] = $pageAccount;
                }

                if ($wantIg) {
                    $connected[] = $this->upsert($userId, Platform::Instagram, $ig['id'], [
                        'name' => $ig['name'] ?? $ig['username'] ?? $ig['id'],
                        'username' => $ig['username'] ?? null,
                        'account_type' => 'instagram_professional',
                        'parent_account_id' => $pageAccount?->id,
                        // Instagram Graph API calls use the linked Page's token.
                        'access_token' => $page['access_token'],
                        'token_expires_at' => null,
                        'scopes' => $scopes,
                        'status' => AccountStatus::Connected,
                        'status_reason' => null,
                        'metadata' => ['page_id' => $page['id'], 'page_name' => $page['name'], 'picture' => $ig['profile_picture_url'] ?? null],
                    ]);
                }
            }
        });

        Cache::forget($this->pendingKey($key));

        return $connected;
    }

    /**
     * Ask Meta whether a stored token is still valid.
     */
    public function checkToken(SocialAccount $account): bool
    {
        $data = $this->graph->appGet('debug_token', [
            'input_token' => $account->access_token,
            'access_token' => $this->graph->appToken(),
        ], 'Meta token check')['data'] ?? [];

        if (! empty($data['scopes'])) {
            $account->forceFill(['scopes' => $data['scopes']])->save();
        }

        return (bool) ($data['is_valid'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(?int $userId, Platform $platform, string $externalId, array $attributes): SocialAccount
    {
        $account = SocialAccount::updateOrCreate(
            ['platform' => $platform->value, 'external_id' => $externalId],
            array_merge($attributes, ['user_id' => $userId, 'last_authenticated_at' => now(), 'auth_problem_notified_at' => null, 'paused_until' => null]),
        );

        PostingRule::firstOrCreate(['social_account_id' => $account->id], PostingRule::defaultsFor($account));
        $this->activity->log('account.connected', $account, "Connected {$platform->label()} {$account->name}");

        return $account;
    }

    /**
     * The selection belongs to the workspace that started the connection.
     */
    private function pendingKey(string $key): string
    {
        return 'meta-connect:'.Tenant::id().':'.$key;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, string>  $pageIds
     * @param  array<int, string>  $instagramIds
     */
    private function assertWithinAccountLimit(array $pages, array $pageIds, array $instagramIds): void
    {
        $limit = Tenant::workspace()?->max_accounts ?? 0;
        if ($limit <= 0) {
            return;
        }

        $known = SocialAccount::pluck('external_id')->all();
        $offered = collect($pages)->flatMap(fn ($p) => [$p['id'], $p['instagram_business_account']['id'] ?? null])->filter()->all();
        $new = collect(array_merge($pageIds, $instagramIds))->intersect($offered)->diff($known)->unique()->count();

        if (SocialAccount::count() + $new > $limit) {
            throw new OAuthException("Your workspace can connect up to {$limit} accounts. Disconnect one first or ask the administrator for a higher limit.");
        }
    }

    /**
     * @return array{pages: array<int, array<string, mixed>>, scopes: array<int, string>}
     */
    private function pending(string $key): array
    {
        $payload = Cache::get($this->pendingKey($key));
        if (! $payload) {
            throw new OAuthException('The Facebook connection expired. Please connect again.');
        }

        return Crypt::decrypt($payload);
    }
}
