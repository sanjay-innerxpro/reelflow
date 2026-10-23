<?php

namespace Tests;

use App\Models\Workspace;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?Workspace $workspace = null;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::forget();

        // Database tests run inside one unlimited workspace, like a signed-in
        // customer; tenancy tests create more and switch explicitly.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->workspace = Workspace::factory()->unlimited()->create(['name' => 'Test workspace']);
            Tenant::set($this->workspace);
        }
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        parent::tearDown();
    }
}
