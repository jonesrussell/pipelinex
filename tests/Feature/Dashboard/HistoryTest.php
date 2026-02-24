<?php

use App\Models\CrawlJob;
use App\Models\User;

test('history page loads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard/history')
        ->assertOk();
});

test('history page shows crawl jobs', function () {
    $user = User::factory()->create();
    $apiKey = $user->tenant->generateApiKey('Test', 'live');

    CrawlJob::factory()->count(3)->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
    ]);

    $this->actingAs($user)
        ->get('/dashboard/history')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/History')
            ->has('crawlJobs.data', 3)
        );
});
