<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\ActivityLog;
use App\Models\SyncDevice;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get('/')->assertOk()->assertSee('Request an invite');
        $this->get(route('videos.index'))->assertRedirect(route('login'));
        $this->get('/horizon')->assertRedirect(route('login'));
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery')]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'nope']);
        }
        $this->post(route('login'), ['email' => $user->email, 'password' => 'nope'])->assertStatus(429);
    }

    public function test_every_page_renders_with_real_data(): void
    {
        $this->fakeInfrastructure();
        [$post, $video, $account] = $this->duePost(Platform::Facebook, ['status' => PostStatus::Failed, 'last_error_message' => 'boom', 'requires_review' => true]);
        SyncDevice::factory()->create();
        ActivityLog::create(['action' => 'test.entry', 'message' => 'hello', 'created_at' => now()]);

        $this->actingAs(User::factory()->admin()->create());

        foreach ([
            route('dashboard'), route('videos.index'), route('videos.show', $video), route('calendar'),
            route('calendar', ['view' => 'day']), route('posts.index'), route('posts.failed'), route('posts.show', $post),
            route('accounts.index'), route('rules.index'), route('rules.edit', $account), route('devices.index'),
            route('settings.email'), route('settings.ai'), route('analytics'), route('logs.index'), route('logs.failed-jobs'),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('posts.show', $post))->assertSee('Manual check required');
        $this->get(route('accounts.index'))->assertDontSee($account->access_token);
    }

    public function test_device_token_is_shown_once_and_stored_hashed(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('devices.store'), ['name' => 'Laptop'])->assertRedirect(route('devices.index'));
        $token = session('new_token');
        $this->assertStringStartsWith('rfd_', $token);

        $device = SyncDevice::first();
        $this->assertNotSame($token, $device->token_hash);
        $this->assertTrue($device->is(SyncDevice::findByToken($token)));
        $this->get(route('devices.index'))->assertSee($token); // the one-time display after creation
        $this->get(route('devices.index'))->assertDontSee($token);

        $this->post(route('devices.revoke', $device))->assertSessionHas('status');
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/sync/heartbeat')->assertStatus(403);
    }

    public function test_caption_editing_and_approval(): void
    {
        $this->fakeInfrastructure();
        $video = $this->readyVideo();
        $this->actingAs(User::factory()->create());

        $this->put(route('content.update', [$video, 'instagram']), [
            'caption' => 'Hand written caption', 'hashtags' => '#one two #three', 'approve' => 1,
        ])->assertSessionHas('status');

        $content = $video->currentContent(Platform::Instagram);
        $this->assertSame('Hand written caption', $content->caption);
        $this->assertSame(['one', 'two', 'three'], $content->hashtags);
        $this->assertSame('approved', $content->validation_status->value);

        $this->put(route('content.update', [$video, 'instagram']), [
            'caption' => 'x', 'hashtags' => '#a #b #c #d #e #f',
        ])->assertSessionHasErrors('hashtags');

        $this->put(route('content.update', [$video, 'youtube']), ['title' => 'Bad <title>'])->assertSessionHasErrors('title');
    }

    public function test_posting_rule_update_and_global_pause(): void
    {
        $this->fakeInfrastructure();
        $account = $this->account(Platform::YouTube, [], ['enabled' => false]);
        $this->actingAs(User::factory()->create());

        $this->put(route('rules.update', $account), [
            'enabled' => 1, 'timezone' => 'Asia/Kolkata', 'daily_limit' => 6, 'window_start' => '07:00', 'window_end' => '22:00',
            'min_interval_minutes' => 60, 'slot_times' => '07:30, 10:00, 25:00, 12:30', 'active_weekdays' => [1, 2, 3, 4, 5],
            'max_attempts' => 4, 'selection_order' => 'random', 'auto_retry' => 1, 'privacy_status' => 'unlisted',
            'folder_filter' => "Cooking/\nTravel",
        ])->assertRedirect(route('rules.index'));

        $rule = $account->postingRule->fresh();
        $this->assertTrue($rule->enabled);
        $this->assertSame(['07:30', '10:00', '12:30'], $rule->slot_times, 'Invalid times are dropped');
        $this->assertSame(['Cooking', 'Travel'], $rule->folder_filter);
        $this->assertSame('unlisted', $rule->privacy_status);

        $this->post(route('rules.global'), ['paused' => 1, 'cross_post_all' => 1])->assertSessionHas('status');
        $this->assertTrue(app(Settings::class)->bool('publishing.paused'));
    }

    public function test_public_legal_pages_are_reachable_without_login(): void
    {
        $this->get(route('legal.privacy'))->assertOk()->assertSee('Limited Use');
        $this->get(route('legal.terms'))->assertOk();
        $this->get(route('legal.data-deletion'))->assertOk()->assertSee('Disconnect');
    }

    public function test_password_can_be_changed(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password-123')]);
        $this->actingAs($user)->get(route('account'))->assertOk();

        $this->put(route('account.password'), ['current_password' => 'wrong', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])
            ->assertSessionHasErrors('current_password');
        $this->put(route('account.password'), ['current_password' => 'old-password-123', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->put(route('account.password'), ['current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
    }
}
