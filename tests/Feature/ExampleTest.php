<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('images/photohub-logo-web.png', false)
            ->assertSee('favicon.png', false);

        $this->get('/images/photohub-logo-web.png')->assertOk();
        $this->get('/favicon.png')->assertOk();
    }
}
