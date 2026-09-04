<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_view_as_owner_in_read_only_mode_and_return(): void
    {
        $this->seed();
        $administrator = User::where('email', 'admin@example.com')->firstOrFail();
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $business = Business::where('slug', 'lenscraft-studio')->firstOrFail();

        $this->actingAs($administrator)->post(route('admin.impersonation.start', $business))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('impersonator_id', $administrator->id)
            ->assertSessionHas('business_id', $business->id);
        $this->assertAuthenticatedAs($owner);
        $this->get(route('dashboard'))->assertOk()->assertSee('View-as-owner mode');
        $this->post(route('tasks.store'), ['title' => 'Should not be created'])->assertForbidden();

        $this->post(route('admin.impersonation.stop'))->assertRedirect(route('admin.index'));
        $this->assertAuthenticatedAs($administrator);
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_ordinary_user_cannot_start_impersonation(): void
    {
        $this->seed();
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $business = Business::where('slug', 'lenscraft-studio')->firstOrFail();

        $this->actingAs($owner)->post(route('admin.impersonation.start', $business))->assertForbidden();
    }
}
