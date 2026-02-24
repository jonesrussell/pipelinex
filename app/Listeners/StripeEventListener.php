<?php

namespace App\Listeners;

use App\Http\Controllers\Dashboard\BillingController;
use App\Models\User;
use Laravel\Cashier\Events\WebhookReceived;

class StripeEventListener
{
    public function handle(WebhookReceived $event): void
    {
        if ($event->payload['type'] === 'customer.subscription.updated') {
            $this->handleSubscriptionUpdated($event->payload);
        }

        if ($event->payload['type'] === 'customer.subscription.deleted') {
            $this->handleSubscriptionDeleted($event->payload);
        }
    }

    private function handleSubscriptionUpdated(array $payload): void
    {
        $stripeCustomerId = $payload['data']['object']['customer'];
        $user = User::where('stripe_id', $stripeCustomerId)->first();

        if (! $user) {
            return;
        }

        $priceId = $payload['data']['object']['items']['data'][0]['price']['id'] ?? null;
        $plan = $this->resolvePlan($priceId);

        BillingController::syncPlan($user, $plan);
    }

    private function handleSubscriptionDeleted(array $payload): void
    {
        $stripeCustomerId = $payload['data']['object']['customer'];
        $user = User::where('stripe_id', $stripeCustomerId)->first();

        if (! $user) {
            return;
        }

        BillingController::syncPlan($user, 'free');
    }

    private function resolvePlan(?string $priceId): string
    {
        $prices = config('services.stripe.prices');

        foreach ($prices as $plan => $id) {
            if ($id === $priceId) {
                return $plan;
            }
        }

        return 'free';
    }
}
