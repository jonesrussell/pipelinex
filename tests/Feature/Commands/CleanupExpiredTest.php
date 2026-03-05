<?php

use App\Models\CrawlJob;
use App\Models\User;

test('cleanup expired command deletes expired jobs', function () {
    $user = User::factory()->create();
    $apiKey = $user->tenant->generateApiKey('Test', 'live');

    // Create an expired job
    CrawlJob::factory()->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
        'expires_at' => now()->subDay(),
    ]);

    // Create a non-expired job
    CrawlJob::factory()->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
        'expires_at' => now()->addDays(30),
    ]);

    $this->artisan('pipelinex:cleanup-expired')
        ->assertSuccessful();

    expect(CrawlJob::count())->toBe(1);
});
