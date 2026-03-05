<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->get('tenant');
        $rpm = $tenant->rate_limit_rpm;
        $key = 'api_rate_limit:'.$tenant->id;

        if (RateLimiter::tooManyAttempts($key, $rpm)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'error' => 'rate_limited',
                'message' => "Too many requests. Retry after {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'X-RateLimit-Limit' => $rpm,
                'X-RateLimit-Remaining' => 0,
                'Retry-After' => $retryAfter,
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        $remaining = RateLimiter::remaining($key, $rpm);
        $response->headers->set('X-RateLimit-Limit', (string) $rpm);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }
}
