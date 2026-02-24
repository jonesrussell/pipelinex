<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnforceQuota;
use App\Http\Middleware\RateLimitApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(AuthenticateApiKey::class)->group(function () {
    Route::post('/crawl', [\App\Http\Controllers\Api\CrawlController::class, 'store'])
        ->middleware([RateLimitApiKey::class, EnforceQuota::class]);
    Route::get('/crawl/{id}', [\App\Http\Controllers\Api\CrawlController::class, 'show']);
    Route::get('/usage', [\App\Http\Controllers\Api\UsageController::class, 'index']);
});
