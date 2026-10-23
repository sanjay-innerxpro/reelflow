<?php

namespace Tests\Feature;

use App\Jobs\WorkerHeartbeatJob;
use App\Models\User;
use App\Support\SystemCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemCheckTest extends TestCase
{
    use RefreshDatabase;

    private function beatAll(): void
    {
        SystemCheck::beat(SystemCheck::SCHEDULER_KEY);
        (new WorkerHeartbeatJob('default'))->handle();
        (new WorkerHeartbeatJob('long'))->handle();
    }

    public function test_health_is_degraded_until_scheduler_and_workers_report(): void
    {
        $this->getJson('/health')->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.scheduler', 'fail')
            ->assertJsonPath('checks.database', 'ok');

        $this->beatAll();

        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_stale_heartbeat_is_reported(): void
    {
        $this->beatAll();
        $this->travel(20)->minutes();
        SystemCheck::beat(SystemCheck::SCHEDULER_KEY);

        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.workers', 'fail');
    }

    public function test_health_reveals_no_configuration(): void
    {
        $body = $this->getJson('/health')->getContent();

        $this->assertStringNotContainsString('test-secret', $body);
        $this->assertStringNotContainsString('META', $body);
    }

    public function test_missing_credentials_are_warnings_not_failures(): void
    {
        Storage::fake('videos');
        config(['reelflow.meta.app_id' => null, 'reelflow.google.client_id' => null, 'reelflow.ai.provider' => 'none']);

        $checks = app(SystemCheck::class)->all();

        $this->assertSame('warn', $checks['meta']['status']);
        $this->assertSame('warn', $checks['google']['status']);
        $this->assertSame('warn', $checks['ai']['status']);
        $this->assertSame('ok', $checks['storage']['status']);
        $this->assertSame('ok', $checks['app_key']['status']);
    }

    public function test_production_requires_https_and_no_debug(): void
    {
        Storage::fake('videos');
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'http://reels.example.com']);

        $checks = app(SystemCheck::class)->all();

        $this->assertSame('fail', $checks['debug']['status']);
        $this->assertSame('fail', $checks['https']['status']);
    }

    public function test_doctor_command_and_dashboard_checklist(): void
    {
        Storage::fake('videos');
        $this->artisan('reels:doctor')->assertFailed(); // no scheduler/worker heartbeat yet

        $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk()->assertSee('Setup checklist')->assertSee('Scheduler (cron)');
        // Server checks are the administrator's business, not each customer's.
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertDontSee('Scheduler (cron)');
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
