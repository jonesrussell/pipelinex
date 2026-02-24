<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCrawlJob;
use App\Models\CrawlJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrawlPlaygroundController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('dashboard/CrawlPlayground');
    }

    public function crawl(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $tenant = $request->user()->tenant;
        $apiKey = $tenant->apiKeys()->whereNull('revoked_at')->first();

        if (! $apiKey) {
            return response()->json(['error' => 'No API key found. Create one first.'], 400);
        }

        $crawlJob = CrawlJob::create([
            'tenant_id' => $tenant->id,
            'api_key_id' => $apiKey->id,
            'url' => $request->url,
            'status' => 'processing',
            'options' => [],
        ]);

        // Dispatch and run synchronously for playground
        ProcessCrawlJob::dispatchSync($crawlJob->id);

        $crawlJob->refresh();
        $crawlJob->load('crawlResult');

        if ($crawlJob->status === 'completed' && $crawlJob->crawlResult) {
            $result = $crawlJob->crawlResult;

            return response()->json([
                'id' => $crawlJob->id,
                'status' => 'completed',
                'url' => $crawlJob->url,
                'final_url' => $crawlJob->final_url,
                'data' => [
                    'title' => $result->title,
                    'author' => $result->author,
                    'published_date' => $result->published_date?->toISOString(),
                    'body' => $result->body,
                    'word_count' => $result->word_count,
                    'quality_score' => $result->quality_score,
                    'topics' => $result->topics,
                    'og' => $result->og,
                    'links' => $result->links,
                    'images' => $result->images,
                ],
                'meta' => [
                    'status_code' => $crawlJob->http_status_code,
                    'content_type' => $result->content_type,
                    'crawled_at' => $crawlJob->completed_at?->toISOString(),
                    'duration_ms' => $crawlJob->duration_ms,
                ],
            ]);
        }

        return response()->json([
            'id' => $crawlJob->id,
            'status' => $crawlJob->status,
            'error' => $crawlJob->error_message,
        ], 422);
    }
}
