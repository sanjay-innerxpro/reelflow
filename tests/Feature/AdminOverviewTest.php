<?php

namespace Tests\Feature;

use App\Models\AiGeneration;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        Tenant::forget();
    }

    public function test_only_an_admin_can_open_the_overview(): void
    {
        $workspace = Workspace::factory()->create(['name' => 'Alpha']);

        $this->actingAs(User::factory()->create(['workspace_id' => $workspace->id]))
            ->get(route('admin.overview'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create(['workspace_id' => $workspace->id]))
            ->get(route('admin.overview'))->assertOk();
    }

    public function test_it_shows_every_workspace_and_why_generations_failed(): void
    {
        $alpha = Workspace::factory()->create(['name' => 'Alpha']);
        $beta = Workspace::factory()->create(['name' => 'Beta']);
        $admin = User::factory()->admin()->create(['workspace_id' => $alpha->id]);

        $video = Tenant::run($beta, fn () => $this->readyVideo(['original_filename' => 'beta-clip.mp4']));
        $this->generation($beta, $video, AiGeneration::STATUS_FAILED, 'AI response failed schema validation. {"topic":["must be a string"]}', '{"captions": {}}');
        $this->generation($beta, $video, AiGeneration::STATUS_FAILED, 'AI provider error (429): rate limit reached');
        $this->generation($beta, $video, AiGeneration::STATUS_SUCCEEDED);

        $response = $this->actingAs($admin)->get(route('admin.overview'))->assertOk();

        // A different workspace's data is the point of this page.
        $response->assertSee('Beta')->assertSee('beta-clip.mp4');
        $response->assertSee('Unusable answer shape')->assertSee('Provider rate limit or quota');
        $response->assertSee('{&quot;captions&quot;: {}}', false);
        $response->assertSee('33%', false); // only one of three finished generations was usable
    }

    private function generation(Workspace $workspace, Video $video, string $status, ?string $error = null, ?string $raw = null): void
    {
        Tenant::run($workspace, fn () => AiGeneration::create([
            'video_id' => $video->id,
            'status' => $status,
            'provider' => 'gemini',
            'model' => 'gemini-flash-latest',
            'prompt_version' => 'v2',
            'error' => $error,
            'raw_output' => $raw,
            'completed_at' => now(),
        ]));
    }
}
