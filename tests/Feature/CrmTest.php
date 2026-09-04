<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $this->seed();

        return [User::where('email', 'owner@example.com')->firstOrFail(), Business::where('slug', 'lenscraft-studio')->firstOrFail()];
    }

    public function test_owner_can_create_customer(): void
    {
        [$u,$b] = $this->owner();
        $this->actingAs($u)->withSession(['business_id' => $b->id])->post('/customers', ['first_name' => 'Kelvin', 'last_name' => 'Mushi', 'phone' => '0700000000', 'status' => 'active'])->assertRedirect();
        $this->assertDatabaseHas('customers', ['business_id' => $b->id, 'customer_number' => 'CUS-000001', 'first_name' => 'Kelvin']);
    }

    public function test_cross_tenant_customer_is_not_visible(): void
    {
        [$u,$b] = $this->owner();
        $other = Business::create(['name' => 'Other', 'slug' => 'other-studio', 'email' => 'other@example.com']);
        $customer = Customer::create(['business_id' => $other->id, 'customer_number' => 'CUS-000001', 'first_name' => 'Hidden', 'last_name' => 'Person', 'phone' => '1']);
        $this->actingAs($u)->withSession(['business_id' => $b->id])->get('/customers/'.$customer->id)->assertNotFound();
    }

    public function test_lead_conversion_is_atomic_and_one_time_only(): void
    {
        [$u,$b] = $this->owner();
        $lead = Lead::create(['business_id' => $b->id, 'name' => 'Anna Joseph', 'phone' => '0711111111']);
        $this->actingAs($u)->withSession(['business_id' => $b->id])->post('/leads/'.$lead->id.'/convert')->assertRedirect();
        $this->assertDatabaseHas('customers', ['business_id' => $b->id, 'first_name' => 'Anna', 'last_name' => 'Joseph']);
        $this->actingAs($u)->withSession(['business_id' => $b->id])->post('/leads/'.$lead->id.'/convert')->assertStatus(422);
    }
}
