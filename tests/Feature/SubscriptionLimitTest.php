<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Gallery;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_plan_gallery_limit_is_enforced(): void
    {
        $this->seed();
        $business = Business::first();
        $plan = SubscriptionPlan::where('slug', 'starter')->first();
        $plan->update(['gallery_limit' => 1]);
        Subscription::create(['business_id' => $business->id, 'subscription_plan_id' => $plan->id, 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'status' => 'active']);
        Gallery::create(['business_id' => $business->id, 'gallery_number' => 'GAL-LIMIT', 'name' => 'Limit', 'code' => 'LIMIT', 'type' => 'proof']);

        $this->expectException(ValidationException::class);
        app(SubscriptionLimitService::class)->assertCanCreateGallery($business);
    }

    public function test_expired_business_cannot_consume_more_storage(): void
    {
        $this->seed();
        $business = Business::first();
        $business->update(['trial_ends_at' => now()->subDay()]);
        $business->subscriptions()->update(['ends_at' => now()->subDay()]);

        $this->expectException(ValidationException::class);
        app(SubscriptionLimitService::class)->assertCanStore($business, 1);
    }
}
