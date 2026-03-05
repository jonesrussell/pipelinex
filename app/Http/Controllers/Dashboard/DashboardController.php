<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CrawlJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant;
        $monthStart = now()->startOfMonth();

        $totalCrawls = CrawlJob::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart)
            ->count();

        $successfulCrawls = CrawlJob::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart)
            ->where('status', 'completed')
            ->count();

        $successRate = $totalCrawls > 0 ? round(($successfulCrawls / $totalCrawls) * 100) : 0;

        $avgSpeed = CrawlJob::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart)
            ->where('status', 'completed')
            ->avg('duration_ms') ?? 0;

        $recentCrawls = CrawlJob::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (CrawlJob $job) => [
                'id' => $job->id,
                'url' => $job->url,
                'status' => $job->status,
                'duration_ms' => $job->duration_ms,
                'created_at' => $job->created_at->diffForHumans(),
            ]);

        $hasApiKey = $tenant->apiKeys()->whereNull('revoked_at')->exists();

        return Inertia::render('Dashboard', [
            'stats' => [
                'crawls_used' => $successfulCrawls,
                'crawl_limit' => $tenant->monthly_crawl_limit,
                'success_rate' => $successRate,
                'avg_speed_ms' => (int) round($avgSpeed),
            ],
            'recentCrawls' => $recentCrawls,
            'hasApiKey' => $hasApiKey,
            'plan' => $tenant->plan,
        ]);
    }
}
