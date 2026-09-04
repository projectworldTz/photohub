<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_module_pages_render(): void
    {
        $this->seed();
        $user = User::where('email', 'owner@example.com')->first();
        $business = Business::where('slug', 'lenscraft-studio')->first();
        $this->actingAs($user);
        foreach (['/dashboard', '/search?q=Kelvin', '/activity', '/customers', '/leads', '/packages', '/staff', '/bookings', '/calendar', '/shoots', '/equipment', '/invoices', '/quotations', '/expenses', '/galleries', '/reports', '/tasks', '/orders', '/print-orders', '/messages', '/contracts', '/reviews', '/settings', '/portfolio', '/notifications'] as $uri) {
            $this->withSession(['business_id' => $business->id])->get($uri)->assertOk($uri);
        }
        $this->withSession(['business_id' => $business->id])->get('/gallery-links')->assertOk();
        $this->withSession(['business_id' => $business->id])->get('/galleries/'.Gallery::first()->id.'/workflow')->assertOk();
    }

    public function test_super_admin_pages_render(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->first();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/plans')->assertOk();
        $this->actingAs($admin)->get('/admin/support')->assertOk();
        $this->actingAs($admin)->get('/admin/businesses/create')->assertOk();
        $this->actingAs($admin)->get('/admin/businesses/'.Business::first()->id)->assertOk();
        $this->actingAs($admin)->get('/dashboard')->assertRedirect('/admin');
    }

    public function test_super_admin_login_redirects_to_platform_dashboard(): void
    {
        $this->seed();

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'PhotoHub2026!'])
            ->assertRedirect(route('admin.index'));
    }
}
