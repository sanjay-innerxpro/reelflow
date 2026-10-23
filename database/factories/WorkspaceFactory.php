<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'storage_limit_mb' => 2048,
            'max_accounts' => 5,
            'daily_ai_limit' => 50,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn () => ['storage_limit_mb' => 0, 'max_accounts' => 0, 'daily_ai_limit' => 0]);
    }
}
