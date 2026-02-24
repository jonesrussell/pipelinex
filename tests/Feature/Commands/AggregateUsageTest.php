<?php

use App\Models\CrawlJob;
use App\Models\User;
use App\Models\UsageRecord;

test('aggregate usage command creates usage records', function () {
    $user = User::factory()->create();
    $apiKey = $user->tenant->generateApiKey('Test', 'live');

    CrawlJob::factory()->count(3)->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
        'status' => 'completed',
        'created_at' => now()->subDay(),
    ]);

    CrawlJob::factory()->create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
        'status' => 'failed',
        'created_at' => now()->subDay(),
    ]);

    $this->artisan('pipelinex:aggregate-usage')
        ->assertSuccessful();

    $record = UsageRecord::where('tenant_id', $user->tenant->id)->first();
    expect($record)->not->toBeNull();
    expect($record->crawls_count)->toBe(4);
    expect($record->crawls_succeeded)->toBe(3);
    expect($record->crawls_failed)->toBe(1);
});
