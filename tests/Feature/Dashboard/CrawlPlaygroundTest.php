<?php

use App\Models\User;

test('crawl playground page loads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard/crawl')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/CrawlPlayground')
        );
});

test('crawl playground requires url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/dashboard/crawl', [])
        ->assertUnprocessable();
});

test('crawl playground returns error without api key', function () {
    $user = User::factory()->create();
    // Revoke any auto-created keys
    $user->tenant->apiKeys()->update(['revoked_at' => now()]);

    $this->actingAs($user)
        ->postJson('/dashboard/crawl', ['url' => 'https://example.com'])
        ->assertStatus(400)
        ->assertJson(['error' => 'No API key found. Create one first.']);
});
