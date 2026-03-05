<?php

use App\Jobs\ProcessCrawlJob;
use App\Models\CrawlJob;
use App\Models\CrawlResult;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('POST /api/v1/crawl requires api key', function () {
    $this->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertUnauthorized();
});

test('POST /api/v1/crawl validates url', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'not-a-url'])
        ->assertUnprocessable();
});

test('POST /api/v1/crawl validates url is required', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', [])
        ->assertUnprocessable();
});

test('POST /api/v1/crawl dispatches job and returns 202 when queue is faked', function () {
    Queue::fake();

    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $response = $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com/article']);

    $response->assertStatus(202)
        ->assertJsonStructure(['id', 'status', 'poll_url']);

    Queue::assertPushed(ProcessCrawlJob::class);
});

test('POST /api/v1/crawl creates crawl job record', function () {
    Queue::fake();

    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com/article']);

    $this->assertDatabaseHas('crawl_jobs', [
        'url' => 'https://example.com/article',
        'status' => 'processing',
        'tenant_id' => $user->tenant->id,
    ]);
});

test('GET /api/v1/crawl/{id} returns completed result', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $crawlJob = CrawlJob::create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $result['apiKey']->id,
        'url' => 'https://example.com/article',
        'status' => 'completed',
        'http_status_code' => 200,
        'duration_ms' => 1500,
        'completed_at' => now(),
    ]);

    CrawlResult::create([
        'crawl_job_id' => $crawlJob->id,
        'title' => 'Test Article',
        'body' => 'Article body text',
        'word_count' => 3,
        'quality_score' => 85,
        'topics' => ['technology'],
        'og' => ['title' => 'Test Article'],
        'links' => [],
        'images' => [],
        'content_type' => 'text/html',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->getJson('/api/v1/crawl/'.$crawlJob->id)
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('data.title', 'Test Article')
        ->assertJsonPath('data.quality_score', 85)
        ->assertJsonStructure([
            'id', 'status', 'url', 'final_url',
            'data' => ['title', 'author', 'body', 'word_count', 'quality_score', 'topics', 'og', 'links', 'images'],
            'meta' => ['status_code', 'content_type', 'crawled_at', 'duration_ms'],
        ]);
});

test('GET /api/v1/crawl/{id} returns processing status', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $crawlJob = CrawlJob::create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $result['apiKey']->id,
        'url' => 'https://example.com/article',
        'status' => 'processing',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->getJson('/api/v1/crawl/'.$crawlJob->id)
        ->assertOk()
        ->assertJsonPath('status', 'processing')
        ->assertJsonStructure(['id', 'status', 'url', 'poll_url']);
});

test('GET /api/v1/crawl/{id} returns 404 for other tenant', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $key1 = $user1->tenant->generateApiKey('Test', 'live');
    $key2 = $user2->tenant->generateApiKey('Test', 'live');

    $crawlJob = CrawlJob::create([
        'tenant_id' => $user1->tenant->id,
        'api_key_id' => $key1['apiKey']->id,
        'url' => 'https://example.com/article',
        'status' => 'processing',
    ]);

    // user2 tries to access user1's crawl job
    $this->withHeader('Authorization', 'Bearer '.$key2['key'])
        ->getJson('/api/v1/crawl/'.$crawlJob->id)
        ->assertNotFound();
});

test('GET /api/v1/crawl/{id} returns 404 for nonexistent job', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->getJson('/api/v1/crawl/crawl_nonexistent')
        ->assertNotFound();
});

test('GET /api/v1/crawl/{id} returns failed status', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $crawlJob = CrawlJob::create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $result['apiKey']->id,
        'url' => 'https://example.com/failing',
        'status' => 'failed',
        'error_code' => 'processing_error',
        'error_message' => 'Connection timeout',
        'completed_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->getJson('/api/v1/crawl/'.$crawlJob->id)
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error.code', 'processing_error')
        ->assertJsonPath('error.message', 'Connection timeout');
});

test('POST /api/v1/crawl stores options', function () {
    Queue::fake();

    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', [
            'url' => 'https://example.com',
            'options' => ['timeout' => 20, 'include_html' => true],
        ])
        ->assertStatus(202);

    $job = CrawlJob::where('tenant_id', $user->tenant->id)->first();
    expect($job->options)->toBe(['timeout' => 20, 'include_html' => true]);
});

test('POST /api/v1/crawl rejects invalid timeout', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('Test', 'live');

    $this->withHeader('Authorization', 'Bearer '.$result['key'])
        ->postJson('/api/v1/crawl', [
            'url' => 'https://example.com',
            'options' => ['timeout' => 999],
        ])
        ->assertUnprocessable();
});
