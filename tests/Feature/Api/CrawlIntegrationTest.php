<?php

use App\Models\CrawlJob;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('full crawl flow returns structured data', function () {
    // Set a non-zero wait timeout so the sync-wait finds the completed job
    config()->set('services.pipelinex.crawl_wait_timeout', 1);

    Http::fake([
        '*/api/internal/v1/fetch' => Http::response([
            'url' => 'https://example.com/article',
            'final_url' => 'https://example.com/article/',
            'status_code' => 200,
            'content_type' => 'text/html',
            'html' => '<html><head><title>Test</title></head><body><article>Hello world</article></body></html>',
            'title' => 'Test',
            'body' => 'Hello world',
            'author' => 'Jane',
            'description' => 'A test article',
            'og' => ['title' => 'Test', 'description' => 'A test article'],
            'duration_ms' => 500,
        ]),
        '*/api/internal/v1/extract' => Http::response([
            'title' => 'Test',
            'body' => 'Hello world',
            'word_count' => 2,
            'quality_score' => 75,
            'topics' => ['technology'],
            'content_type' => 'article',
            'og' => [],
            'author' => 'Jane',
            'published_date' => null,
        ]),
    ]);

    $user = User::factory()->create();
    $tenant = $user->tenant;
    $key = $tenant->generateApiKey('Test', 'live');

    $response = $this->withHeader('Authorization', 'Bearer ' . $key['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com/article']);

    // With sync queue driver, job runs immediately; with timeout=1, poll finds it
    $response->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('data.title', 'Test')
        ->assertJsonPath('data.quality_score', 75)
        ->assertJsonPath('data.topics', ['technology'])
        ->assertJsonPath('data.word_count', 2)
        ->assertJsonPath('meta.status_code', 200)
        ->assertJsonStructure([
            'id', 'status', 'url', 'final_url',
            'data' => ['title', 'author', 'published_date', 'body', 'word_count', 'quality_score', 'topics', 'og', 'links', 'images'],
            'meta' => ['status_code', 'content_type', 'crawled_at', 'duration_ms'],
        ]);

    // Verify the job was stored
    $crawlJob = CrawlJob::first();
    expect($crawlJob->status)->toBe('completed');
    expect($crawlJob->crawlResult)->not->toBeNull();

    // Verify rate limit headers
    $response->assertHeader('X-RateLimit-Limit');
    $response->assertHeader('X-RateLimit-Remaining');
});

test('full crawl flow handles NC failure', function () {
    config()->set('services.pipelinex.crawl_wait_timeout', 1);

    Http::fake([
        '*/api/internal/v1/fetch' => Http::response(['error' => 'timeout'], 504),
    ]);

    $user = User::factory()->create();
    $key = $user->tenant->generateApiKey('Test', 'live');

    $response = $this->withHeader('Authorization', 'Bearer ' . $key['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com/failing']);

    // The job fails, and sync-wait finds the failed status
    $response->assertStatus(502)
        ->assertJsonPath('status', 'failed')
        ->assertJsonStructure(['id', 'status', 'error' => ['code', 'message']]);
});

test('retrieve completed crawl via GET endpoint', function () {
    config()->set('services.pipelinex.crawl_wait_timeout', 1);

    Http::fake([
        '*/api/internal/v1/fetch' => Http::response([
            'url' => 'https://example.com/article',
            'final_url' => 'https://example.com/article/',
            'status_code' => 200,
            'content_type' => 'text/html',
            'html' => '<html><body>Test</body></html>',
            'title' => 'Test',
            'body' => 'Test',
            'author' => null,
            'description' => null,
            'og' => [],
            'duration_ms' => 300,
        ]),
        '*/api/internal/v1/extract' => Http::response([
            'title' => 'Test',
            'body' => 'Test content',
            'word_count' => 2,
            'quality_score' => 60,
            'topics' => ['general'],
            'content_type' => 'article',
            'og' => [],
            'author' => null,
            'published_date' => null,
        ]),
    ]);

    $user = User::factory()->create();
    $key = $user->tenant->generateApiKey('Test', 'live');

    // First, crawl
    $crawlResponse = $this->withHeader('Authorization', 'Bearer ' . $key['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com/article']);

    $crawlResponse->assertOk();
    $crawlId = $crawlResponse->json('id');

    // Then retrieve by ID
    $this->withHeader('Authorization', 'Bearer ' . $key['key'])
        ->getJson('/api/v1/crawl/' . $crawlId)
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('data.title', 'Test');
});

test('usage endpoint reflects completed crawls', function () {
    config()->set('services.pipelinex.crawl_wait_timeout', 1);

    Http::fake([
        '*/api/internal/v1/fetch' => Http::response([
            'url' => 'https://example.com',
            'final_url' => 'https://example.com/',
            'status_code' => 200,
            'content_type' => 'text/html',
            'html' => '<html><body>Test</body></html>',
            'title' => 'Test',
            'body' => 'Test',
            'author' => null,
            'description' => null,
            'og' => [],
            'duration_ms' => 200,
        ]),
        '*/api/internal/v1/extract' => Http::response([
            'title' => 'Test',
            'body' => 'Test',
            'word_count' => 1,
            'quality_score' => 50,
            'topics' => [],
            'content_type' => 'article',
            'og' => [],
            'author' => null,
            'published_date' => null,
        ]),
    ]);

    $user = User::factory()->create();
    $key = $user->tenant->generateApiKey('Test', 'live');

    // Do a crawl
    $this->withHeader('Authorization', 'Bearer ' . $key['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com']);

    // Check usage
    $this->withHeader('Authorization', 'Bearer ' . $key['key'])
        ->getJson('/api/v1/usage')
        ->assertOk()
        ->assertJsonPath('crawls.used', 1)
        ->assertJsonPath('crawls.remaining', 99);
});
