<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    private const PLAN_CONFIG = [
        'starter' => [
            'monthly_crawl_limit' => 1000,
            'rate_limit_rpm' => 20,
        ],
        'pro' => [
            'monthly_crawl_limit' => 10000,
            'rate_limit_rpm' => 60,
        ],
        'free' => [
            'monthly_crawl_limit' => 100,
            'rate_limit_rpm' => 5,
        ],
    ];

    public function checkout(Request $request, string $plan): RedirectResponse
    {
        $request->validate(['plan' => 'in:starter,pro']);

        $priceId = config("services.stripe.prices.{$plan}");

        if (! $priceId) {
            abort(404, 'Plan not found');
        }

        return $request->user()
            ->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => route('dashboard.usage').'?checkout=success',
                'cancel_url' => route('dashboard.usage').'?checkout=cancel',
            ])
            ->redirect();
    }

    public function portal(Request $request): RedirectResponse
    {
        return $request->user()->redirectToBillingPortal(
            route('dashboard.usage')
        );
    }

    public static function syncPlan(\App\Models\User $user, string $plan): void
    {
        $config = self::PLAN_CONFIG[$plan] ?? self::PLAN_CONFIG['free'];

        $user->tenant->update([
            'plan' => $plan,
            'monthly_crawl_limit' => $config['monthly_crawl_limit'],
            'rate_limit_rpm' => $config['rate_limit_rpm'],
        ]);
    }
}
