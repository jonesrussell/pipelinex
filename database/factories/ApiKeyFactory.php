<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rawKey = 'px_live_'.Str::random(32);

        return [
            'tenant_id' => Tenant::factory(),
            'key_hash' => hash('sha256', $rawKey),
            'key_prefix' => Str::substr($rawKey, 0, 20),
            'environment' => 'live',
            'name' => fake()->words(2, true).' key',
        ];
    }

    /**
     * Indicate a test environment key.
     */
    public function test(): static
    {
        return $this->state(function (array $attributes) {
            $rawKey = 'px_test_'.Str::random(32);

            return [
                'key_hash' => hash('sha256', $rawKey),
                'key_prefix' => Str::substr($rawKey, 0, 20),
                'environment' => 'test',
            ];
        });
    }

    /**
     * Indicate a revoked key.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
