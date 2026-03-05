<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CrawlRequest;
use App\Jobs\ProcessCrawlJob;
use App\Models\CrawlJob;
use Illuminate\Http\JsonResponse;

class CrawlController extends Controller
{
    public function store(CrawlRequest $request): JsonResponse
    {
        $tenant = $request->get('tenant');
        $apiKey = $request->get('api_key');

        $crawlJob = CrawlJob::create([
            'tenant_id' => $tenant->id,
            'api_key_id' => $apiKey->id,
            'url' => $request->validated('url'),
            'status' => 'processing',
            'options' => $request->validated('options', []),
        ]);

        try {
            ProcessCrawlJob::dispatch($crawlJob->id);
        } catch (\Throwable) {
            // Sync queue driver re-throws job exceptions; the job already
            // marked the CrawlJob as failed in its catch block, so we
            // just swallow the exception here and let the status check
            // below handle it.
        }

        // Sync-wait: poll DB until result arrives or timeout
        $waitTimeout = (int) config('services.pipelinex.crawl_wait_timeout', 30);
        $result = $this->waitForResult($crawlJob->id, $waitTimeout);

        if ($result) {
            $crawlJob->refresh();
            $crawlJob->load('crawlResult');

            if ($crawlJob->status === 'completed' && $crawlJob->crawlResult) {
                return response()->json($this->formatResponse($crawlJob), 200);
            }

            if ($crawlJob->status === 'failed') {
                return response()->json([
                    'id' => $crawlJob->id,
                    'status' => 'failed',
                    'error' => [
                        'code' => $crawlJob->error_code,
                        'message' => $crawlJob->error_message,
                    ],
                ], 502);
            }
        }

        // Async fallback: return 202 with poll URL
        return response()->json([
            'id' => $crawlJob->id,
            'status' => 'processing',
            'url' => $crawlJob->url,
            'poll_url' => '/api/v1/crawl/'.$crawlJob->id,
        ], 202);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = request()->get('tenant');

        $crawlJob = CrawlJob::with('crawlResult')
            ->where('tenant_id', $tenant->id)
            ->find($id);

        if (! $crawlJob) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Crawl result not found or expired',
            ], 404);
        }

        if ($crawlJob->status === 'processing') {
            return response()->json([
                'id' => $crawlJob->id,
                'status' => 'processing',
                'url' => $crawlJob->url,
                'poll_url' => '/api/v1/crawl/'.$crawlJob->id,
            ]);
        }

        if ($crawlJob->status === 'failed') {
            return response()->json([
                'id' => $crawlJob->id,
                'status' => 'failed',
                'url' => $crawlJob->url,
                'error' => [
                    'code' => $crawlJob->error_code,
                    'message' => $crawlJob->error_message,
                ],
            ]);
        }

        if (! $crawlJob->crawlResult) {
            return response()->json([
                'id' => $crawlJob->id,
                'status' => 'failed',
                'error' => [
                    'code' => 'result_missing',
                    'message' => 'Crawl result data is unavailable',
                ],
            ], 500);
        }

        return response()->json($this->formatResponse($crawlJob));
    }

    private function waitForResult(string $jobId, int $timeoutSeconds): bool
    {
        $deadline = now()->addSeconds($timeoutSeconds);

        while (now()->lt($deadline)) {
            $job = CrawlJob::find($jobId);
            if ($job && $job->status !== 'processing') {
                return true;
            }
            usleep(250_000); // 250ms
        }

        return false;
    }

    private function formatResponse(CrawlJob $crawlJob): array
    {
        $result = $crawlJob->crawlResult;

        return [
            'id' => $crawlJob->id,
            'status' => $crawlJob->status,
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
        ];
    }
}
