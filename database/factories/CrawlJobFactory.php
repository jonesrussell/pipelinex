<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CrawlJob>
 */
class CrawlJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 'crawl_'.Str::random(12),
            'tenant_id' => Tenant::factory(),
            'api_key_id' => ApiKey::factory(),
            'url' => fake()->url(),
            'status' => 'processing',
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * Indicate the crawl job has completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'http_status_code' => 200,
            'duration_ms' => fake()->numberBetween(100, 5000),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate the crawl job has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_code' => 'timeout',
            'error_message' => 'Request timed out',
            'completed_at' => now(),
        ]);
    }
}
