<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFixtures;
use Tests\TestCase;

class GuideTest extends TestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    public function test_the_guide_explains_the_flow_and_keeps_admin_notes_to_admins(): void
    {
        $this->fakeInfrastructure();
        Tenant::forget();
        $workspace = Workspace::factory()->create();

        $this->get(route('guide'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['workspace_id' => $workspace->id]))
            ->get(route('guide'))->assertOk()
            ->assertSee('Add videos')
            ->assertSee('Published')
            ->assertDontSee('For administrators');

        $this->actingAs(User::factory()->admin()->create(['workspace_id' => $workspace->id]))
            ->get(route('guide'))->assertOk()->assertSee('For administrators');
    }
}
