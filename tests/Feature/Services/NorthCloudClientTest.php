<?php

use App\Services\NorthCloudClient;
use Illuminate\Support\Facades\Http;

test('fetch calls crawler endpoint', function () {
    Http::fake([
        '*/api/internal/v1/fetch' => Http::response([
            'url' => 'https://example.com',
            'title' => 'Test',
            'body' => 'Hello',
            'status_code' => 200,
        ]),
    ]);

    $client = new NorthCloudClient;
    $result = $client->fetch('https://example.com');

    expect($result['title'])->toBe('Test');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/internal/v1/fetch') &&
        $request->hasHeader('X-Internal-Secret')
    );
});

test('extract calls classifier endpoint', function () {
    Http::fake([
        '*/api/internal/v1/extract' => Http::response([
            'title' => 'Test',
            'body' => 'Content',
            'quality_score' => 85,
            'topics' => ['tech'],
        ]),
    ]);

    $client = new NorthCloudClient;
    $result = $client->extract('<html>test</html>', 'https://example.com');

    expect($result['quality_score'])->toBe(85);
});

test('healthy returns true when services respond', function () {
    Http::fake([
        '*/health' => Http::response(['status' => 'ok']),
    ]);

    $client = new NorthCloudClient;
    expect($client->healthy())->toBeTrue();
});

test('healthy returns false when services are down', function () {
    Http::fake([
        '*/health' => Http::response(null, 500),
    ]);

    $client = new NorthCloudClient;
    expect($client->healthy())->toBeFalse();
});
