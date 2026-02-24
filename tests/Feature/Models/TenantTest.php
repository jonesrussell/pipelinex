<?php

use App\Models\ApiKey;
use App\Models\CrawlJob;
use App\Models\Tenant;
use App\Models\User;

test('tenant is created when user registers', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    expect($tenant)->toBeInstanceOf(Tenant::class);
    expect($tenant->plan)->toBe('free');
    expect($tenant->monthly_crawl_limit)->toBe(100);
    expect($tenant->rate_limit_rpm)->toBe(5);
});

test('tenant can generate api key', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    $result = $tenant->generateApiKey('Test Key', 'live');

    expect($result['key'])->toStartWith('px_live_');
    expect($result['apiKey'])->toBeInstanceOf(ApiKey::class);
    expect($result['apiKey']->name)->toBe('Test Key');
});

test('api key can be verified by raw key', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    $result = $tenant->generateApiKey('Test Key', 'live');
    $rawKey = $result['key'];

    $found = ApiKey::findByRawKey($rawKey);

    expect($found)->not->toBeNull();
    expect($found->tenant_id)->toBe($tenant->id);
});

test('revoked api key is not found', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    $result = $tenant->generateApiKey('Test Key', 'live');
    $result['apiKey']->update(['revoked_at' => now()]);

    $found = ApiKey::findByRawKey($result['key']);
    expect($found)->toBeNull();
});

test('crawl job auto-generates id and expires_at', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $apiKeyResult = $tenant->generateApiKey('Test', 'live');

    $job = CrawlJob::create([
        'tenant_id' => $tenant->id,
        'api_key_id' => $apiKeyResult['apiKey']->id,
        'url' => 'https://example.com',
        'status' => 'processing',
    ]);

    expect($job->id)->toStartWith('crawl_');
    expect(strlen($job->id))->toBe(18);
    expect($job->expires_at)->not->toBeNull();
});

test('crawl job can be marked as completed', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $apiKeyResult = $tenant->generateApiKey('Test', 'live');

    $job = CrawlJob::create([
        'tenant_id' => $tenant->id,
        'api_key_id' => $apiKeyResult['apiKey']->id,
        'url' => 'https://example.com',
        'status' => 'processing',
    ]);

    $job->markCompleted(200, 1500, 'https://example.com/final');

    expect($job->fresh()->status)->toBe('completed');
    expect($job->fresh()->http_status_code)->toBe(200);
    expect($job->fresh()->duration_ms)->toBe(1500);
    expect($job->fresh()->final_url)->toBe('https://example.com/final');
    expect($job->fresh()->completed_at)->not->toBeNull();
});

test('crawl job can be marked as failed', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $apiKeyResult = $tenant->generateApiKey('Test', 'live');

    $job = CrawlJob::create([
        'tenant_id' => $tenant->id,
        'api_key_id' => $apiKeyResult['apiKey']->id,
        'url' => 'https://example.com',
        'status' => 'processing',
    ]);

    $job->markFailed('timeout', 'Request timed out');

    expect($job->fresh()->status)->toBe('failed');
    expect($job->fresh()->error_code)->toBe('timeout');
    expect($job->fresh()->error_message)->toBe('Request timed out');
    expect($job->fresh()->completed_at)->not->toBeNull();
});

test('api key isRevoked returns correct value', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    $result = $tenant->generateApiKey('Test Key', 'live');
    $apiKey = $result['apiKey'];

    expect($apiKey->isRevoked())->toBeFalse();

    $apiKey->update(['revoked_at' => now()]);

    expect($apiKey->fresh()->isRevoked())->toBeTrue();
});

test('api key touchLastUsed updates timestamp', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    $result = $tenant->generateApiKey('Test Key', 'live');
    $apiKey = $result['apiKey'];

    expect($apiKey->last_used_at)->toBeNull();

    $apiKey->touchLastUsed();

    expect($apiKey->fresh()->last_used_at)->not->toBeNull();
});

test('tenant has correct relationships', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    expect($tenant->user)->toBeInstanceOf(User::class);
    expect($tenant->apiKeys)->toBeEmpty();
    expect($tenant->crawlJobs)->toBeEmpty();
    expect($tenant->usageRecords)->toBeEmpty();
});

test('tenant factory state methods work correctly', function () {
    $starter = Tenant::factory()->starter()->create();
    expect($starter->plan)->toBe('starter');
    expect($starter->monthly_crawl_limit)->toBe(1000);
    expect($starter->rate_limit_rpm)->toBe(20);

    $pro = Tenant::factory()->pro()->create();
    expect($pro->plan)->toBe('pro');
    expect($pro->monthly_crawl_limit)->toBe(10000);
    expect($pro->rate_limit_rpm)->toBe(60);
});
