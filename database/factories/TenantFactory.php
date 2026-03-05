<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => 'free',
            'monthly_crawl_limit' => 100,
            'rate_limit_rpm' => 5,
        ];
    }

    /**
     * Configure the model factory.
     *
     * When the UserFactory auto-creates a default tenant via afterCreating,
     * this factory must remove it first to avoid a unique constraint violation.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Tenant $tenant) {
            // When UserFactory auto-creates a default tenant via afterCreating,
            // delete it to avoid a unique constraint violation on user_id.
            Tenant::where('user_id', $tenant->user_id)->delete();
        });
    }

    /**
     * Indicate a starter plan tenant.
     */
    public function starter(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'starter',
            'monthly_crawl_limit' => 1000,
            'rate_limit_rpm' => 20,
        ]);
    }

    /**
     * Indicate a pro plan tenant.
     */
    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'pro',
            'monthly_crawl_limit' => 10000,
            'rate_limit_rpm' => 60,
        ]);
    }
}
