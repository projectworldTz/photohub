<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Gallery;
use App\Models\SubscriptionPlan;
use Illuminate\Validation\ValidationException;

class SubscriptionLimitService
{
    public function usage(Business $business): array
    {
        $subscription = $business->subscriptions()->with('plan')->where('status', 'active')->where('ends_at', '>', now())->latest('ends_at')->first();
        $plan = $subscription?->plan;
        $trial = ! $plan && $business->trial_ends_at?->isFuture();
        if ($trial) {
            $plan = SubscriptionPlan::where('slug', 'starter')->first();
        }

        return [
            'subscription' => $subscription,
            'plan' => $plan,
            'trial' => $trial,
            'galleries' => Gallery::withTrashed()->forBusiness($business->id)->count(),
            'storage_mb' => round(app(StorageQuotaService::class)->usage($business)['used'] / 1048576, 2),
        ];
    }

    public function assertCanCreateGallery(Business $business): void
    {
        if (PhotoStorage::isLocal()) {
            return;
        }
        $usage = $this->usage($business);
        $this->assertEntitled($usage);
        if ($usage['galleries'] >= $usage['plan']->gallery_limit) {
            throw ValidationException::withMessages(['subscription' => 'Your gallery limit has been reached. Upgrade your plan to create another gallery.']);
        }
    }

    public function assertCanStore(Business $business, int $additionalBytes): void
    {
        if (PhotoStorage::isLocal()) {
            return;
        }
        $usage = $this->usage($business);
        $this->assertEntitled($usage);
        app(StorageQuotaService::class)->assertFits($business->fresh(), $additionalBytes);
    }

    private function assertEntitled(array $usage): void
    {
        if (! $usage['plan']) {
            throw ValidationException::withMessages(['subscription' => 'Your trial or subscription has expired. Select a plan to continue.']);
        }
    }
}
