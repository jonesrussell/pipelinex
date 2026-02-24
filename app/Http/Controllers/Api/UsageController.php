<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['message' => 'not implemented'], 501);
    }
}
