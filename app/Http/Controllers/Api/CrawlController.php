<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrawlController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'not implemented'], 501);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['message' => 'not implemented'], 501);
    }
}
