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

test('history detail shows crawl job', function () {
    $user = User::factory()->create();
    $apiKey = $user->tenant->generateApiKey('Test', 'live');

    $crawlJob = CrawlJob::factory()->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
    ]);

    $this->actingAs($user)
        ->get('/dashboard/history/'.$crawlJob->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/HistoryDetail')
            ->has('crawlJob')
        );
});

test('history detail returns 403 for other tenant', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $apiKey = $user1->tenant->generateApiKey('Test', 'live');

    $crawlJob = CrawlJob::factory()->create([
        'tenant_id' => $user1->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
    ]);

    $this->actingAs($user2)
        ->get('/dashboard/history/'.$crawlJob->id)
        ->assertForbidden();
});
