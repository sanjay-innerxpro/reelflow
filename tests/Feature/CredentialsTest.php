<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Credentials;
use App\Support\Tenancy\Tenant;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CredentialsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::forget();
        $workspace = Workspace::factory()->create();
        $this->admin = User::factory()->admin()->create(['workspace_id' => $workspace->id]);
    }

    public function test_only_an_admin_can_see_or_change_credentials(): void
    {
        $user = User::factory()->create(['workspace_id' => $this->admin->workspace_id]);

        $this->actingAs($user)->get(route('admin.credentials'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.credentials.update'), ['meta_app_id' => '123456'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.credentials.test', 'meta'))->assertForbidden();
        $this->assertSame(0, SystemSetting::count());

        $this->actingAs($this->admin)->get(route('admin.credentials'))->assertOk()->assertSee('Facebook &amp; Instagram', false);
    }

    public function test_saved_values_are_encrypted_and_override_env(): void
    {
        $this->save(['meta_app_id' => '1234567890', 'meta_app_secret' => 'panel-secret-abcdef123456', 'google_client_id' => 'abc.apps.googleusercontent.com'])
            ->assertSessionHas('status');

        // Encrypted at rest.
        $raw = DB::table('system_settings')->where('key', 'meta.app_secret')->value('value');
        $this->assertNotSame('panel-secret-abcdef123456', $raw);
        $this->assertStringNotContainsString('panel-secret', $raw);

        // In effect at once, and after a fresh boot.
        $this->assertSame('panel-secret-abcdef123456', config('reelflow.meta.app_secret'));
        config(['reelflow.meta.app_id' => 'test-app', 'reelflow.meta.app_secret' => 'test-secret']);
        (new Credentials)->apply();
        $this->assertSame('1234567890', config('reelflow.meta.app_id'));
        $this->assertSame('panel-secret-abcdef123456', config('reelflow.meta.app_secret'));
        $this->assertSame('abc.apps.googleusercontent.com', config('reelflow.google.client_id'));
        $this->assertSame('test-secret', config('reelflow.google.client_secret'), 'unset fields keep the .env value');

        // The page shows a mask, never the secret.
        $this->actingAs($this->admin)->get(route('admin.credentials'))
            ->assertOk()->assertSee('••••3456')->assertDontSee('panel-secret-abcdef123456');
    }

    public function test_an_empty_secret_keeps_the_saved_value_and_remove_falls_back_to_env(): void
    {
        $this->save(['meta_app_secret' => 'panel-secret-abcdef123456', 'meta_app_id' => '1234567890']);

        $this->save(['meta_app_secret' => '', 'meta_app_id' => '1234567890'])->assertSessionHas('status', 'Nothing changed.');
        $this->assertSame('panel-secret-abcdef123456', config('reelflow.meta.app_secret'));

        $this->save(['meta_app_secret' => '', 'clear' => ['meta.app_secret'], 'meta_app_id' => '']);
        $this->assertSame('test-secret', config('reelflow.meta.app_secret'));
        $this->assertSame('test-app', config('reelflow.meta.app_id'), 'an emptied field falls back to .env');
        $this->assertSame(0, SystemSetting::count());
    }

    public function test_ai_keys_mail_and_youtube_values_are_cast(): void
    {
        $this->save([
            'ai_provider' => 'gemini',
            'ai_api_keys' => "key-one-aaaaaaaa\nkey-two-bbbbbbbb, key-one-aaaaaaaa",
            'mail_mailer' => 'smtp',
            'mail_port' => '465',
            'mail_scheme' => 'smtps',
            'mail_password' => 'mailbox-password',
            'youtube_enabled' => '1',
        ]);

        $this->assertSame('gemini', config('reelflow.ai.provider'));
        $this->assertSame(['key-one-aaaaaaaa', 'key-two-bbbbbbbb'], config('reelflow.ai.api_keys'));
        $this->assertSame('key-one-aaaaaaaa', config('reelflow.ai.api_key'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('mailbox-password', config('mail.mailers.smtp.password'));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertTrue(config('reelflow.youtube_enabled'));

        $this->save(['youtube_enabled' => '0']);
        $this->assertFalse(config('reelflow.youtube_enabled'));
    }

    public function test_invalid_values_are_rejected_and_secrets_are_not_flashed_back(): void
    {
        $this->save(['meta_app_id' => 'not-a-number', 'meta_app_secret' => 'panel-secret-abcdef123456', 'ai_provider' => 'skynet'])
            ->assertSessionHasErrors(['meta_app_id', 'ai_provider']);

        $this->assertSame(0, SystemSetting::count());
        $this->assertArrayNotHasKey('meta_app_secret', session()->getOldInput());
    }

    public function test_redirect_uris_default_to_the_app_callbacks(): void
    {
        config(['app.url' => 'https://reels.example.com', 'reelflow.meta.redirect_uri' => null, 'reelflow.google.redirect_uri' => '']);

        (new Credentials)->apply();

        $this->assertSame('https://reels.example.com/oauth/meta/callback', config('reelflow.meta.redirect_uri'));
        $this->assertSame('https://reels.example.com/oauth/google/callback', config('reelflow.google.redirect_uri'));
    }

    public function test_a_value_saved_under_another_app_key_falls_back_to_env(): void
    {
        $other = new Encrypter(random_bytes(32), 'AES-256-CBC');
        DB::table('system_settings')->insert(['key' => 'meta.app_secret', 'value' => $other->encryptString('old'), 'created_at' => now(), 'updated_at' => now()]);

        $credentials = new Credentials;
        $credentials->apply();

        $this->assertTrue($credentials->isUnreadable('meta.app_secret'));
        $this->assertSame('test-secret', config('reelflow.meta.app_secret'));
    }

    public function test_connection_tests_report_what_the_providers_answer(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '1234567890', 'name' => 'ReelFlow'], 200),
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'], 400),
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['data' => []], 200)
                ->push([['error' => ['message' => 'API key not valid.']]], 400),
        ]);
        $this->save(['ai_provider' => 'gemini', 'ai_api_keys' => "good-key-12345678\nbad-key-123456789"]);

        $this->check('meta')->assertSessionHas('credential_test.ok', true);
        $this->assertStringContainsString('ReelFlow', session('credential_test.message'));

        $this->check('google')->assertSessionHas('credential_test.ok', true);

        $this->check('ai')->assertSessionHas('credential_test.ok', false);
        $message = session('credential_test.message');
        $this->assertStringContainsString('Key 1 (••••5678): OK', $message);
        $this->assertStringContainsString('API key not valid', $message);
        $this->assertStringNotContainsString('bad-key-123456789', $message);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && $request['access_token'] === 'test-app|test-secret');
    }

    public function test_google_reports_a_wrong_client_secret(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => 'Unauthorized'], 401)]);

        $this->check('google')->assertSessionHas('credential_test.ok', false);
        $this->assertStringContainsString('rejected', session('credential_test.message'));
    }

    public function test_import_copies_what_env_sets_and_keeps_saved_values(): void
    {
        $this->save(['meta_app_secret' => 'panel-secret-abcdef123456']);

        $credentials = app(Credentials::class);
        $imported = $credentials->importFromEnv($this->admin->id);

        // phpunit.xml sets META_APP_ID, GOOGLE_CLIENT_SECRET, …
        $this->assertContains('meta.app_id', $imported);
        $this->assertContains('google.client_secret', $imported);
        $this->assertSame('test-app', $credentials->stored('meta.app_id'));
        $this->assertNotContains('meta.app_secret', $imported, 'a value saved in the panel is kept');
        $this->assertSame('panel-secret-abcdef123456', config('reelflow.meta.app_secret'));
        // …but not these.
        $this->assertNotContains('meta.config_id', $imported);
        $this->assertNotContains('ai.api_keys', $imported);
    }

    public function test_the_deploy_import_runs_only_once(): void
    {
        $this->artisan('reels:import-credentials', ['--once' => true])->assertSuccessful();
        $this->assertTrue(SystemSetting::where('key', 'meta.app_id')->exists());

        SystemSetting::where('key', 'meta.app_id')->delete();
        $this->artisan('reels:import-credentials', ['--once' => true])->expectsOutputToContain('nothing to do')->assertSuccessful();
        $this->assertFalse(SystemSetting::where('key', 'meta.app_id')->exists());
    }

    public function test_the_import_button_reports_what_it_copied(): void
    {
        $this->actingAs($this->admin)->from(route('admin.credentials'))->post(route('admin.credentials.import'))
            ->assertRedirect(route('admin.credentials'))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Meta App ID'));

        $this->actingAs($this->admin)->get(route('admin.credentials'))->assertOk()->assertSee('saved here');
    }

    private function save(array $input)
    {
        return $this->actingAs($this->admin)->from(route('admin.credentials'))->put(route('admin.credentials.update'), $input);
    }

    private function check(string $group)
    {
        return $this->actingAs($this->admin)->post(route('admin.credentials.test', $group))->assertRedirect(route('admin.credentials').'#'.$group);
    }
}
