<?php

use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('rejects request without api key', function () {
    $this->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'missing_api_key']);
});

test('rejects request with invalid api key', function () {
    $this->withHeader('Authorization', 'Bearer px_live_invalidkey123')
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'invalid_api_key']);
});

test('rejects request with revoked api key', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $result = $tenant->generateApiKey('Test', 'live');
    $result['apiKey']->update(['revoked_at' => now()]);

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertUnauthorized();
});

test('accepts request with valid api key', function () {
    Queue::fake();

    $user = User::factory()->create();
    $tenant = $user->tenant;
    $result = $tenant->generateApiKey('Test', 'live');

    // Should get past auth — controller now dispatches job and returns 202
    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertStatus(202);
});
