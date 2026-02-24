<?php

use App\Models\CrawlJob;
use App\Models\User;

test('GET /api/v1/usage returns usage stats', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    // Create some completed crawl jobs
    CrawlJob::factory()->count(3)->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $result['apiKey']->id,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer ' . $result['key'])
        ->getJson('/api/v1/usage')
        ->assertOk()
        ->assertJsonPath('plan', 'free')
        ->assertJsonPath('crawls.used', 3)
        ->assertJsonPath('crawls.limit', 100)
        ->assertJsonPath('crawls.remaining', 97)
        ->assertJsonStructure([
            'plan',
            'period' => ['start', 'end'],
            'crawls' => ['used', 'limit', 'remaining'],
            'rate_limit' => ['requests_per_minute'],
        ]);
});

test('GET /api/v1/usage only counts current month', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    // Create crawl jobs from last month (should not count)
    CrawlJob::factory()->count(5)->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $result['apiKey']->id,
        'status' => 'completed',
        'completed_at' => now()->subMonth(),
        'created_at' => now()->subMonth(),
    ]);

    // Create crawl jobs from this month (should count)
    CrawlJob::factory()->count(2)->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $result['apiKey']->id,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer ' . $result['key'])
        ->getJson('/api/v1/usage')
        ->assertOk()
        ->assertJsonPath('crawls.used', 2);
});

test('GET /api/v1/usage requires auth', function () {
    $this->getJson('/api/v1/usage')
        ->assertUnauthorized();
});
