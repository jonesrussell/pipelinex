<?php

use App\Models\User;

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
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $result = $tenant->generateApiKey('Test', 'live');

    // Should get past auth — will get 501 from placeholder controller
    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertStatus(501); // placeholder returns 501
});
