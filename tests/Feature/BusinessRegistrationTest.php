<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_tenant_owner_and_membership(): void
    {
        $this->seed();
        $response = $this->post('/register', ['business_name' => 'Aurora Photos', 'owner_name' => 'Jane Doe', 'phone' => '+255700000000', 'email' => 'jane@example.com', 'city' => 'Arusha', 'country' => 'Tanzania', 'category' => 'Wedding', 'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'password' => 'SecurePass9', 'password_confirmation' => 'SecurePass9']);
        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('businesses', ['slug' => 'aurora-photos']);
        $this->assertDatabaseHas('business_user', ['employee_number' => 'EMP-000001']);
    }
}
