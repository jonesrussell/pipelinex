<?php

namespace App\Http\Middleware;

use App\Models\CrawlJob;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->get('tenant');
        $limit = $tenant->monthly_crawl_limit;

        $used = CrawlJob::where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        if ($used >= $limit) {
            return response()->json([
                'error' => 'quota_exceeded',
                'message' => 'Monthly crawl limit reached. Upgrade your plan.',
                'limit' => $limit,
                'used' => $used,
            ], 402);
        }

        return $next($request);
    }
}
