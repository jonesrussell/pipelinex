<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrawlJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class UsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->get('tenant');

        $used = CrawlJob::where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $rateLimitKey = 'api_rate_limit:'.$tenant->id;
        $currentRate = $tenant->rate_limit_rpm - RateLimiter::remaining($rateLimitKey, $tenant->rate_limit_rpm);

        return response()->json([
            'plan' => $tenant->plan,
            'period' => [
                'start' => now()->startOfMonth()->toISOString(),
                'end' => now()->endOfMonth()->toISOString(),
            ],
            'crawls' => [
                'used' => $used,
                'limit' => $tenant->monthly_crawl_limit,
                'remaining' => max(0, $tenant->monthly_crawl_limit - $used),
            ],
            'rate_limit' => [
                'requests_per_minute' => $tenant->rate_limit_rpm,
                'current' => $currentRate,
            ],
        ]);
    }
}
