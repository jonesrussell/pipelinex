<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CrawlJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UsageDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant;

        $used = CrawlJob::where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $dailyUsage = CrawlJob::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total, SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) as succeeded, SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failed')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        return Inertia::render('dashboard/Usage', [
            'plan' => $tenant->plan,
            'crawls' => [
                'used' => $used,
                'limit' => $tenant->monthly_crawl_limit,
            ],
            'rateLimit' => $tenant->rate_limit_rpm,
            'dailyUsage' => $dailyUsage,
        ]);
    }
}
