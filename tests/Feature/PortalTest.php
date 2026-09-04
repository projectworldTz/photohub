<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_login_redirects_to_isolated_portal(): void
    {
        $this->seed();
        $this->post('/login', ['email' => 'customer@example.com', 'password' => 'PhotoHub2026!'])->assertRedirect('/portal');
        $u = User::where('email', 'customer@example.com')->first();
        $this->actingAs($u)->get('/portal')->assertOk()->assertSee('Kelvin Mushi');
    }

    public function test_staff_cannot_impersonate_customer_portal(): void
    {
        $this->seed();
        $u = User::where('email', 'owner@example.com')->first();
        $this->actingAs($u)->get('/portal')->assertNotFound();
    }
}
