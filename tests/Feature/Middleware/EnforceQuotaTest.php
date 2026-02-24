<?php

use App\Models\CrawlJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('allows request when quota not exceeded', function () {
    Queue::fake();

    $user = User::factory()->create();
    $tenant = $user->tenant;
    $result = $tenant->generateApiKey('Test', 'live');

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertStatus(202); // passes quota, controller dispatches job and returns 202
});

test('rejects request when quota exceeded', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $tenant->update(['monthly_crawl_limit' => 2]);
    $result = $tenant->generateApiKey('Test', 'live');

    // Create 2 completed crawl jobs to fill quota
    CrawlJob::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'api_key_id' => $result['apiKey']->id,
        'status' => 'completed',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertStatus(402)
        ->assertJson(['error' => 'quota_exceeded']);
});
