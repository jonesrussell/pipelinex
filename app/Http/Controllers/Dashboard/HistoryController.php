<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CrawlJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant;

        $crawlJobs = CrawlJob::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (CrawlJob $job) => [
                'id' => $job->id,
                'url' => $job->url,
                'status' => $job->status,
                'duration_ms' => $job->duration_ms,
                'created_at' => $job->created_at->toFormattedDateString(),
            ]);

        return Inertia::render('dashboard/History', [
            'crawlJobs' => $crawlJobs,
        ]);
    }

    public function show(Request $request, CrawlJob $crawlJob): Response
    {
        $tenant = $request->user()->tenant;

        if ($crawlJob->tenant_id !== $tenant->id) {
            abort(403);
        }

        $crawlJob->load('crawlResult');

        return Inertia::render('dashboard/HistoryDetail', [
            'crawlJob' => [
                'id' => $crawlJob->id,
                'url' => $crawlJob->url,
                'final_url' => $crawlJob->final_url,
                'status' => $crawlJob->status,
                'http_status_code' => $crawlJob->http_status_code,
                'duration_ms' => $crawlJob->duration_ms,
                'error_code' => $crawlJob->error_code,
                'error_message' => $crawlJob->error_message,
                'created_at' => $crawlJob->created_at->toISOString(),
                'completed_at' => $crawlJob->completed_at?->toISOString(),
                'result' => $crawlJob->crawlResult ? [
                    'title' => $crawlJob->crawlResult->title,
                    'author' => $crawlJob->crawlResult->author,
                    'body' => $crawlJob->crawlResult->body,
                    'word_count' => $crawlJob->crawlResult->word_count,
                    'quality_score' => $crawlJob->crawlResult->quality_score,
                    'topics' => $crawlJob->crawlResult->topics,
                    'og' => $crawlJob->crawlResult->og,
                    'content_type' => $crawlJob->crawlResult->content_type,
                ] : null,
            ],
        ]);
    }
}
