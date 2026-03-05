<?php

use App\Jobs\ProcessCrawlJob;
use App\Models\CrawlJob;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('ProcessCrawlJob fetches and extracts content', function () {
    Http::fake([
        '*/api/internal/v1/fetch' => Http::response([
            'url' => 'https://example.com/article',
            'final_url' => 'https://example.com/article/',
            'status_code' => 200,
            'content_type' => 'text/html',
            'html' => '<html><body>Hello</body></html>',
            'title' => 'Test Article',
            'body' => 'Hello',
            'author' => 'Author Name',
            'description' => 'A test article',
            'og' => ['title' => 'Test Article'],
            'duration_ms' => 500,
        ]),
        '*/api/internal/v1/extract' => Http::response([
            'title' => 'Test Article',
            'body' => '# Test Article\n\nHello world',
            'word_count' => 4,
            'quality_score' => 75,
            'topics' => ['technology'],
            'topic_scores' => [['topic' => 'technology', 'score' => 0.9]],
            'content_type' => 'article',
            'og' => ['title' => 'Test Article'],
            'author' => 'Author Name',
            'published_date' => '2026-02-20T10:00:00Z',
        ]),
    ]);

    $user = User::factory()->create();
    $apiKey = $user->tenant->generateApiKey('Test', 'live');

    $crawlJob = CrawlJob::create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
        'url' => 'https://example.com/article',
        'status' => 'processing',
    ]);

    $job = new ProcessCrawlJob($crawlJob->id);
    $job->handle(app(\App\Services\NorthCloudClient::class));

    $crawlJob->refresh();
    expect($crawlJob->status)->toBe('completed');
    expect($crawlJob->http_status_code)->toBe(200);
    expect($crawlJob->final_url)->toBe('https://example.com/article/');

    $result = $crawlJob->crawlResult;
    expect($result)->not->toBeNull();
    expect($result->title)->toBe('Test Article');
    expect($result->word_count)->toBe(4);
    expect($result->quality_score)->toBe(75);
    expect($result->topics)->toBe(['technology']);
});

test('ProcessCrawlJob marks job as failed on error', function () {
    Http::fake([
        '*/api/internal/v1/fetch' => Http::response(['error' => 'timeout'], 504),
    ]);

    $user = User::factory()->create();
    $apiKey = $user->tenant->generateApiKey('Test', 'live');

    $crawlJob = CrawlJob::create([
        'tenant_id' => $user->tenant->id,
        'api_key_id' => $apiKey['apiKey']->id,
        'url' => 'https://example.com/failing',
        'status' => 'processing',
    ]);

    $job = new ProcessCrawlJob($crawlJob->id);

    try {
        $job->handle(app(\App\Services\NorthCloudClient::class));
    } catch (\Throwable) {
        // Expected
    }

    $crawlJob->refresh();
    expect($crawlJob->status)->toBe('failed');
    expect($crawlJob->error_code)->toBe('processing_error');
});
