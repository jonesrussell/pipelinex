<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\Dashboard\DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('dashboard/crawl', [\App\Http\Controllers\Dashboard\CrawlPlaygroundController::class, 'index'])
        ->name('dashboard.crawl');

    Route::post('dashboard/crawl', [\App\Http\Controllers\Dashboard\CrawlPlaygroundController::class, 'crawl'])
        ->name('dashboard.crawl.execute');

    Route::get('dashboard/history', [\App\Http\Controllers\Dashboard\HistoryController::class, 'index'])
        ->name('dashboard.history');

    Route::get('dashboard/history/{crawlJob}', [\App\Http\Controllers\Dashboard\HistoryController::class, 'show'])
        ->name('dashboard.history.show');

    Route::get('dashboard/api-keys', [\App\Http\Controllers\Dashboard\ApiKeyController::class, 'index'])
        ->name('dashboard.api-keys');

    Route::post('dashboard/api-keys', [\App\Http\Controllers\Dashboard\ApiKeyController::class, 'store'])
        ->name('dashboard.api-keys.store');

    Route::delete('dashboard/api-keys/{apiKey}', [\App\Http\Controllers\Dashboard\ApiKeyController::class, 'destroy'])
        ->name('dashboard.api-keys.destroy');

    Route::get('dashboard/usage', [\App\Http\Controllers\Dashboard\UsageDashboardController::class, 'index'])
        ->name('dashboard.usage');

    Route::get('dashboard/billing/checkout/{plan}', [\App\Http\Controllers\Dashboard\BillingController::class, 'checkout'])
        ->name('dashboard.billing.checkout')
        ->where('plan', 'starter|pro');

    Route::get('dashboard/billing/portal', [\App\Http\Controllers\Dashboard\BillingController::class, 'portal'])
        ->name('dashboard.billing.portal');
});

require __DIR__.'/settings.php';
