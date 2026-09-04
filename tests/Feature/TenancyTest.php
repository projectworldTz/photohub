<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_cannot_activate_another_business_through_session(): void
    {
        $own = Business::create(['name' => 'Own', 'slug' => 'own', 'email' => 'own@test.com']);
        $other = Business::create(['name' => 'Other', 'slug' => 'other', 'email' => 'other@test.com']);
        $user = User::factory()->create();
        BusinessUser::create(['business_id' => $own->id, 'user_id' => $user->id]);
        $this->actingAs($user)->withSession(['business_id' => $other->id])->get('/dashboard')->assertOk();
        $this->assertSame($own->id, session('business_id'));
    }

    public function test_inactive_business_is_denied(): void
    {
        $business = Business::create(['name' => 'Paused', 'slug' => 'paused', 'email' => 'paused@test.com', 'status' => 'suspended']);
        $user = User::factory()->create();
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $user->id]);
        $this->actingAs($user)->withSession(['business_id' => $business->id])->get('/dashboard')->assertForbidden();
    }
}
