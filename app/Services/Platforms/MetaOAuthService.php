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
use Illuminate\Support\Facades\Log;
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
            // Without this, Facebook never asks again for a permission the
            // person declined once, and the login silently comes back without it.
            'auth_type' => 'rerequest',
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

            $found = $this->fetchPages($userToken, $granted);
        } catch (PublishException $e) {
            throw new OAuthException('Meta authorisation failed: '.$e->getMessage(), 0, $e);
        }

        // A Page without a Page token (e.g. a Business portfolio Page the
        // person has no task on) can be listed but never published to.
        $pages = array_values(array_filter($found, fn ($p) => filled($p['access_token'] ?? null)));
        $unavailable = array_values(array_map(fn ($p) => $p['name'] ?? $p['id'], array_filter($found, fn ($p) => blank($p['access_token'] ?? null))));

        if ($pages === []) {
            $missing = array_diff(['pages_show_list', 'pages_manage_posts'], $granted);
            throw new OAuthException(match (true) {
                $missing !== [] => $this->missingPermissionMessage($userToken, $missing),
                $unavailable !== [] => 'Facebook lists '.implode(', ', $unavailable).' but gives ReelFlow no access to post there. In Meta Business Suite, give your Facebook profile full control (or "Content") of the Page, then connect again.',
                default => 'Facebook shared no Pages with ReelFlow. Connect again and, in the Facebook window, choose "Edit previous settings" and tick every Page you want to use. '
                    .'Facebook does not let apps post to a personal profile, only to Pages; if you have no Page yet, create one (free) at facebook.com/pages/create and link your Instagram professional account to it.',
            });
        }

        $key = Str::random(40);
        Cache::put($this->pendingKey($key), Crypt::encrypt(['pages' => $pages, 'scopes' => $granted, 'unavailable' => $unavailable]), now()->addMinutes(15));

        return $key;
    }

    /**
     * Name the Facebook account that logged in and, when it is not one of
     * the Meta app's roles, say that this is why (unreviewed apps only
     * grant Page permissions to their Administrators, Developers and Testers).
     *
     * @param  array<int, string>  $missing
     */
    private function missingPermissionMessage(string $userToken, array $missing): string
    {
        $message = 'Facebook did not grant '.implode(' and ', $missing).'.';

        try {
            $me = $this->graph->get('me', $userToken, ['fields' => 'id,name'], 'Meta profile');
            $roles = $this->graph->appGet(config('reelflow.meta.app_id').'/roles', ['access_token' => $this->graph->appToken(), 'limit' => 500], 'Meta app roles')['data'] ?? [];
            $role = collect($roles)->firstWhere('user', $me['id'] ?? null)['role'] ?? null;
        } catch (PublishException) {
            return $message.' Connect again and allow every permission. If Facebook never showed them, add your Facebook account under App roles in developers.facebook.com (the app has not passed App Review yet).';
        }

        $who = 'You signed in to Facebook as '.($me['name'] ?? 'an unknown account');
        if ($role === null && filled($me['id'] ?? null)) {
            // Try to invite this account as a Tester through the Graph API.
            try {
                $this->graph->appPost(config('reelflow.meta.app_id').'/roles', ['user' => $me['id'], 'role' => 'testers'], 'Meta add tester');

                return $message.' '.$who.', who had no role on the Meta app, so ReelFlow has invited this account as a Tester. '
                    .'Accept the invitation at developers.facebook.com/requests (signed in to Facebook as this account), then connect again.';
            } catch (PublishException $e) {
                Log::info('Meta tester invite failed', ['error' => $e->getMessage()]);
                $message .= ' (ReelFlow tried to add you as a Tester automatically, but Meta refused: '.$e->getMessage().')';
            }
        }
        if ($role === null) {
            return $message.' '.$who.', who has no role on the Meta app. Until the app passes App Review, Facebook only gives Page permissions to the app\'s Administrators, Developers and Testers: '
                .'add this account in developers.facebook.com → your app → App roles, accept the invitation, then connect again. Or sign in to Facebook with the account that is the app\'s administrator.';
        }

        return $message.' '.$who.' ('.rtrim($role, 's').' of the Meta app). Connect again and, in the Facebook window, choose "Edit previous settings" and switch on every permission and Page. '
            .'If the permissions are not offered at all, add them to the app in developers.facebook.com → Use cases → "Manage everything on your Page".';
    }

    private const PAGE_FIELDS = 'id,name,access_token,category,tasks,picture{url},instagram_business_account{id,username,name,profile_picture_url}';

    /**
     * Pages on the person's own profile (me/accounts) plus the Pages they
     * reach through a Business portfolio, which me/accounts leaves out.
     *
     * @param  array<int, string>  $granted
     * @return array<int, array<string, mixed>>
     */
    private function fetchPages(string $userToken, array $granted): array
    {
        $pages = [];
        foreach ($this->paginate('me/accounts', $userToken, ['fields' => self::PAGE_FIELDS, 'limit' => 100], 'Meta pages') as $page) {
            $pages[$page['id']] = $page;
        }

        if (! in_array('business_management', $granted, true)) {
            return array_values($pages);
        }

        // Business lookups are a bonus: never fail the connection over them.
        try {
            $businesses = $this->paginate('me/businesses', $userToken, ['fields' => 'id,name', 'limit' => 100], 'Meta businesses');
        } catch (PublishException) {
            $businesses = [];
        }

        foreach ($businesses as $business) {
            foreach (['owned_pages', 'client_pages'] as $edge) {
                try {
                    $found = $this->paginate($business['id'].'/'.$edge, $userToken, ['fields' => self::PAGE_FIELDS, 'limit' => 100], 'Meta business pages');
                } catch (PublishException) {
                    continue;
                }
                foreach ($found as $page) {
                    if (isset($page['id']) && ! isset($pages[$page['id']])) {
                        $pages[$page['id']] = $page + ['business' => $business['name'] ?? null];
                    }
                }
            }
        }

        return array_values($pages);
    }

    /**
     * Follow Graph cursor paging (Meta returns at most 100 items per call).
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function paginate(string $path, string $token, array $query, string $context, int $maxCalls = 10): array
    {
        $items = [];
        for ($i = 0; $i < $maxCalls; $i++) {
            $response = $this->graph->get($path, $token, $query, $context);
            array_push($items, ...array_values($response['data'] ?? []));

            $after = $response['paging']['cursors']['after'] ?? null;
            if (! $after || empty($response['paging']['next'])) {
                break;
            }
            $query['after'] = $after;
        }

        return $items;
    }

    /**
     * Page list for the selection screen (no tokens).
     *
     * @return array{pages: array<int, array<string, mixed>>, scopes: array<int, string>, unavailable: array<int, string>}
     */
    public function pendingSelection(string $key): array
    {
        $data = $this->pending($key);

        return [
            'scopes' => $data['scopes'],
            'unavailable' => $data['unavailable'] ?? [],
            'pages' => array_map(fn ($p) => [
                'id' => $p['id'],
                'name' => $p['name'],
                'category' => $p['category'] ?? null,
                'business' => $p['business'] ?? null,
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
