<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\PostingRule;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_meta_connect_redirects_with_state_and_scopes(): void
    {
        $response = $this->actingAs($this->user)->get(route('accounts.meta.connect'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v23.0/dialog/oauth?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(session('oauth.meta.state')['value'], $query['state']);
        $this->assertStringContainsString('instagram_content_publish', $query['scope']);
        $this->assertStringContainsString('pages_manage_posts', $query['scope']);
    }

    public function test_login_for_business_configuration_replaces_scope(): void
    {
        config(['reelflow.meta.config_id' => '987654321']);

        $location = $this->actingAs($this->user)->get(route('accounts.meta.connect'))->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('987654321', $query['config_id']);
        $this->assertArrayNotHasKey('scope', $query);
    }

    public function test_callback_with_wrong_state_is_rejected(): void
    {
        Http::fake();
        $this->actingAs($this->user)->get(route('accounts.meta.connect'));

        $this->get(route('accounts.meta.callback', ['code' => 'abc', 'state' => 'forged']))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHasErrors('oauth');
        Http::assertNothingSent();
    }

    public function test_denied_authorisation_is_reported(): void
    {
        $this->actingAs($this->user)->get(route('accounts.meta.connect'));
        $state = session('oauth.meta.state')['value'];

        $this->get(route('accounts.meta.callback', ['error' => 'access_denied', 'error_description' => 'Permissions error', 'state' => $state]))
            ->assertSessionHasErrors('oauth');
    }

    public function test_meta_flow_connects_pages_and_instagram(): void
    {
        Http::fake([
            'graph.facebook.com/v23.0/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'EAAshort', 'token_type' => 'bearer'])
                ->push(['access_token' => 'EAAlonglived', 'expires_in' => 5184000]),
            'graph.facebook.com/v23.0/me/permissions*' => Http::response(['data' => [
                ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                ['permission' => 'instagram_content_publish', 'status' => 'granted'],
                ['permission' => 'business_management', 'status' => 'declined'],
            ]]),
            'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [[
                'id' => 'page1', 'name' => 'My Kitchen', 'access_token' => 'EAApagetoken', 'category' => 'Food',
                'tasks' => ['CREATE_CONTENT', 'MANAGE'],
                'instagram_business_account' => ['id' => 'ig1', 'username' => 'mykitchen'],
            ]]]),
        ]);

        $this->actingAs($this->user)->get(route('accounts.meta.connect'));
        $state = session('oauth.meta.state')['value'];

        $select = $this->get(route('accounts.meta.callback', ['code' => 'code123', 'state' => $state]));
        $select->assertRedirect();
        $key = basename(parse_url($select->headers->get('Location'), PHP_URL_PATH));

        $this->get(route('accounts.meta.select', $key))->assertOk()->assertSee('My Kitchen')->assertSee('@mykitchen')->assertDontSee('EAApagetoken');

        $this->post(route('accounts.meta.store', $key), ['pages' => ['page1'], 'instagram' => ['ig1']])
            ->assertRedirect(route('accounts.index'));

        $page = SocialAccount::where('platform', Platform::Facebook)->first();
        $ig = SocialAccount::where('platform', Platform::Instagram)->first();
        $this->assertSame('EAApagetoken', $page->access_token);
        $this->assertSame(['pages_manage_posts', 'instagram_content_publish'], $page->scopes);
        $this->assertSame($page->id, $ig->parent_account_id);
        $this->assertSame('mykitchen', $ig->username);
        $this->assertSame(AccountStatus::Connected, $ig->status);
        $this->assertFalse(PostingRule::where('social_account_id', $ig->id)->value('enabled'), 'Rules start disabled');

        // Tokens are encrypted at rest.
        $raw = \DB::table('social_accounts')->where('id', $page->id)->value('access_token');
        $this->assertNotSame('EAApagetoken', $raw);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fb_exchange_token=EAAshort'));
    }

    public function test_google_flow_uses_pkce_and_stores_refresh_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.access', 'refresh_token' => '1//refresh-token-123456789012345', 'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
            ]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UC123', 'snippet' => ['title' => 'Kitchen Channel', 'customUrl' => '@kitchen'], 'status' => ['privacyStatus' => 'public'],
            ]]]),
        ]);

        $redirect = $this->actingAs($this->user)->get(route('accounts.google.connect'))->headers->get('Location');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('offline', $query['access_type']);
        $verifier = session('oauth.google.verifier');

        $this->get(route('accounts.google.callback', ['code' => 'gcode', 'state' => $query['state']]))
            ->assertRedirect(route('accounts.index'))->assertSessionHas('status');

        $account = SocialAccount::where('platform', Platform::YouTube)->first();
        $this->assertSame('Kitchen Channel', $account->name);
        $this->assertSame('1//refresh-token-123456789012345', $account->refresh_token);
        $this->assertTrue($account->token_expires_at->isFuture());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['code_verifier'] === $verifier);
    }

    public function test_google_without_upload_scope_is_refused(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.a', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/youtube.readonly'])]);
        $redirect = $this->actingAs($this->user)->get(route('accounts.google.connect'))->headers->get('Location');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);

        $this->get(route('accounts.google.callback', ['code' => 'g', 'state' => $query['state']]))->assertSessionHasErrors('oauth');
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_disconnect_deletes_tokens_and_disables_rule(): void
    {
        Http::fake();
        $account = SocialAccount::factory()->youtube()->create();
        PostingRule::factory()->create(['social_account_id' => $account->id, 'platform' => Platform::YouTube]);

        $this->actingAs($this->user)->delete(route('accounts.disconnect', $account))->assertSessionHas('status');

        $account->refresh();
        $this->assertNull($account->access_token);
        $this->assertNull($account->refresh_token);
        $this->assertSame(AccountStatus::Disconnected, $account->status);
        $this->assertFalse($account->postingRule->enabled);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
    }
}
