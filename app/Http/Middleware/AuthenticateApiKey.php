<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer || ! str_starts_with($bearer, 'px_')) {
            return response()->json([
                'error' => 'missing_api_key',
                'message' => 'Provide API key via Authorization: Bearer px_...',
            ], 401);
        }

        $apiKey = ApiKey::findByRawKey($bearer);

        if (! $apiKey) {
            return response()->json([
                'error' => 'invalid_api_key',
                'message' => 'API key not found or revoked',
            ], 401);
        }

        $apiKey->touchLastUsed();

        $request->merge([
            'tenant' => $apiKey->tenant,
            'api_key' => $apiKey,
        ]);

        return $next($request);
    }
}
