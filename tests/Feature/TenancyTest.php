<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\AiGeneration;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\SyncDevice;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Services\Ai\AiBudgetExceeded;
use App\Services\Ai\AiCaptionService;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiVisionProvider;
use App\Services\Drive\DriveImporter;
use App\Services\Storage\VideoStorage;
use App\Support\Settings;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BuildsFixtures;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Workspaces never see or touch each other's data.
 */
class TenancyTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        Tenant::forget();
        $this->a = Workspace::factory()->create(['name' => 'Alpha']);
        $this->b = Workspace::factory()->create(['name' => 'Beta']);
        $this->userA = User::factory()->create(['workspace_id' => $this->a->id]);
        $this->userB = User::factory()->create(['workspace_id' => $this->b->id]);
        $this->a->update(['owner_id' => $this->userA->id]);
        $this->b->update(['owner_id' => $this->userB->id]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function as(Workspace $workspace, callable $callback): mixed
    {
        return Tenant::run($workspace, $callback(...));
    }

    public function test_pages_only_show_and_open_the_own_workspace(): void
    {
        $videoA = $this->as($this->a, fn () => $this->readyVideo(['original_filename' => 'alpha-clip.mp4']));
        [$postB, $videoB, $accountB] = $this->as($this->b, fn () => $this->duePost(Platform::Facebook));
        $videoB->update(['original_filename' => 'beta-secret.mp4']);

        $this->actingAs($this->userA);
        $this->get(route('videos.index'))->assertOk()->assertSee('alpha-clip.mp4')->assertDontSee('beta-secret.mp4');
        $this->get(route('videos.show', $videoA))->assertOk();
        $this->get(route('videos.show', $videoB))->assertNotFound();
        $this->patch(route('videos.update', $videoB), ['category' => 'x'])->assertNotFound();
        $this->get(route('posts.show', $postB))->assertNotFound();
        $this->get(route('rules.edit', $accountB))->assertNotFound();
        $this->post(route('accounts.pause', $accountB))->assertNotFound();
        $this->post(route('videos.schedule', $videoA), ['account_ids' => [$accountB->id]])->assertSessionHasErrors('account_ids.0');
        $this->get(route('accounts.index'))->assertOk()->assertDontSee($accountB->name);
    }

    public function test_sync_device_cannot_reach_another_workspace(): void
    {
        [, $token] = $this->as($this->a, fn () => SyncDevice::issue('Alpha PC'));
        $videoB = $this->as($this->b, fn () => $this->readyVideo());
        $api = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);

        $api->getJson("/api/sync/videos/{$videoB->id}")->assertNotFound();
        $api->postJson('/api/sync/videos/lookup', ['checksums' => [$videoB->checksum_sha256]])
            ->assertOk()->assertExactJson(['videos' => []]);
    }

    public function test_the_same_file_is_a_separate_video_in_each_workspace(): void
    {
        $content = $this->fakeVideoBytes(3000);
        $register = fn (string $token) => $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/sync/videos', [
            'filename' => 'clip.mp4', 'folder_label' => 'Reels', 'relative_path' => 'clip.mp4',
            'size' => strlen($content), 'checksum_sha256' => hash('sha256', $content),
        ]);
        [, $tokenA] = $this->as($this->a, fn () => SyncDevice::issue('A'));
        [, $tokenB] = $this->as($this->b, fn () => SyncDevice::issue('B'));

        $register($tokenA)->assertCreated()->assertJsonPath('video.duplicate', false);
        $register($tokenB)->assertCreated()->assertJsonPath('video.duplicate', false);

        $this->assertSame(2, Video::allWorkspaces()->where('checksum_sha256', hash('sha256', $content))->count());
        $storage = app(VideoStorage::class);
        $this->assertNotSame($storage->keyFor($this->a->id, 'ab', 'mp4'), $storage->keyFor($this->b->id, 'ab', 'mp4'));
    }

    public function test_planner_only_posts_a_workspaces_own_videos(): void
    {
        $this->as($this->b, function () {
            $video = $this->readyVideo();
            $this->contentFor($video, Platform::Facebook);
        });
        $accountA = $this->as($this->a, fn () => $this->account(Platform::Facebook));

        $this->artisan('reels:plan')->assertSuccessful();
        $this->assertSame(0, ScheduledPost::allWorkspaces()->count(), 'Beta\'s video is never offered to Alpha\'s Page');

        $videoA = $this->as($this->a, function () {
            $video = $this->readyVideo();
            $this->contentFor($video, Platform::Facebook);

            return $video;
        });
        $this->artisan('reels:plan')->assertSuccessful();

        $posts = ScheduledPost::allWorkspaces()->get();
        $this->assertSame([$videoA->id], $posts->pluck('video_id')->unique()->values()->all());
        $this->assertSame([$this->a->id], $posts->pluck('workspace_id')->unique()->values()->all());
        $this->assertSame($accountA->id, $posts->first()->social_account_id);
    }

    public function test_settings_are_per_workspace(): void
    {
        $this->as($this->a, fn () => app(Settings::class)->set(['publishing.require_approval' => true]));

        $this->assertTrue($this->as($this->a, fn () => app(Settings::class)->bool('publishing.require_approval')));
        $this->assertFalse($this->as($this->b, fn () => app(Settings::class)->bool('publishing.require_approval')));
        $this->assertSame([$this->userB->email], $this->as($this->b, fn () => app(Settings::class)->recipients()), 'Emails go to the workspace owner by default');
    }

    public function test_tenant_records_cannot_be_created_without_a_workspace(): void
    {
        $this->expectException(LogicException::class);
        Video::factory()->create();
    }

    public function test_storage_limit_blocks_agent_uploads_and_drive_imports(): void
    {
        $this->b->update(['storage_limit_mb' => 1]);
        $this->as($this->b, fn () => $this->readyVideo(['size_bytes' => 900 * 1024], withFile: false));
        [, $token] = $this->as($this->b, fn () => SyncDevice::issue('B'));

        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/sync/videos', [
            'filename' => 'big.mp4', 'folder_label' => 'Reels', 'relative_path' => 'big.mp4',
            'size' => 200 * 1024, 'checksum_sha256' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonPath('error', 'storage_limit');

        $file = $this->as($this->b, function () {
            $folder = DriveFolder::create(['folder_id' => 'folderBBBBBBBBBB', 'name' => 'Drive']);

            return $folder->files()->create(['file_id' => 'fileBBBBBBBBBBB', 'name' => 'big.mp4', 'size_bytes' => 200 * 1024]);
        });
        Http::fake();
        $this->assertSame(DriveImporter::QUOTA, $this->as($this->b, fn () => app(DriveImporter::class)->import($file)));
        $this->assertSame(DriveFile::PENDING, $file->fresh()->status);
        $this->assertSame(0, $file->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_daily_ai_limit_is_per_workspace(): void
    {
        $this->app->instance(AiProviderFactory::class, new class extends AiProviderFactory
        {
            public function vision(): AiVisionProvider
            {
                return new FakeAiProvider;
            }
        });
        $this->b->update(['daily_ai_limit' => 1]);
        $video = $this->as($this->b, fn () => $this->readyVideo());
        $this->as($this->b, fn () => AiGeneration::forceCreate(['id' => (string) Str::uuid(), 'video_id' => $video->id, 'status' => 'succeeded', 'provider' => 'fake', 'model' => 'fake', 'prompt_version' => 'v1']));

        $this->expectException(AiBudgetExceeded::class);
        $this->as($this->b, fn () => app(AiCaptionService::class)->generate($video, (string) Str::uuid()));
    }

    public function test_suspended_workspace_cannot_sign_in_and_is_not_published(): void
    {
        [$post] = $this->as($this->b, fn () => $this->duePost(Platform::Facebook));
        $this->b->update(['suspended_at' => now()]);

        $this->post('/login', ['email' => $this->userB->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->artisan('reels:dispatch')->assertSuccessful();
        $this->assertNull($post->fresh()->claim_token, 'Nothing is published for a suspended workspace');

        $this->actingAs($this->userB)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_admin_invites_a_user_who_sets_a_password_and_gets_an_empty_workspace(): void
    {
        $admin = User::factory()->admin()->create(['workspace_id' => $this->a->id]);
        $this->actingAs($this->userB)->get(route('admin.users.index'))->assertForbidden();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Priya', 'email' => 'priya@example.com', 'storage_limit_mb' => 2048, 'max_accounts' => 5, 'daily_ai_limit' => 50,
        ])->assertRedirect(route('admin.users.index'));
        $url = $response->getSession()->get('invite_url');
        $this->assertNotNull($url);

        $priya = User::where('email', 'priya@example.com')->firstOrFail();
        $this->assertNotContains($priya->workspace_id, [$this->a->id, $this->b->id]);
        $this->assertSame($priya->id, $priya->workspace->owner_id);
        $this->assertFalse($priya->isAdmin());

        auth()->logout();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->get($url)->assertOk()->assertSee('Set your password');
        $this->post(route('password.update'), [
            'token' => basename((string) parse_url($url, PHP_URL_PATH)), 'email' => 'priya@example.com', 'invite' => '1',
            'password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password',
        ])->assertRedirect(route('login'));

        $this->post('/login', ['email' => 'priya@example.com', 'password' => 'a-long-new-password'])->assertRedirect(route('dashboard'));
        $this->get(route('videos.index'))->assertOk()->assertDontSee('alpha')->assertDontSee('beta');
        $this->assertNotNull($priya->fresh()->last_login_at);
    }

    public function test_account_limit_applies_when_connecting_facebook(): void
    {
        $this->a->update(['max_accounts' => 1]);
        $this->as($this->a, fn () => $this->account(Platform::Facebook));
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::sequence()->push(['access_token' => 'short'])->push(['access_token' => 'long']),
            'graph.facebook.com/*/me/permissions*' => Http::response(['data' => []]),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [['id' => 'page2', 'name' => 'Second Page', 'access_token' => 'tok', 'tasks' => ['CREATE_CONTENT']]]]),
        ]);
        config(['reelflow.meta.app_id' => '1', 'reelflow.meta.app_secret' => 's', 'reelflow.meta.redirect_uri' => 'https://x/cb']);

        $this->actingAs($this->userA);
        parse_str((string) parse_url($this->get(route('accounts.meta.connect'))->headers->get('Location'), PHP_URL_QUERY), $q);
        $key = basename((string) parse_url($this->get(route('accounts.meta.callback', ['code' => 'c', 'state' => $q['state']]))->headers->get('Location'), PHP_URL_PATH));

        // Another workspace cannot use this pending selection.
        $this->actingAs($this->userB)->get(route('accounts.meta.select', $key))->assertRedirect(route('accounts.index'));

        $this->actingAs($this->userA)->post(route('accounts.meta.store', $key), ['pages' => ['page2']])
            ->assertSessionHasErrors('oauth');
        $this->assertSame(1, SocialAccount::allWorkspaces()->where('workspace_id', $this->a->id)->count());
    }
}
