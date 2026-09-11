<?php

namespace Tests\Feature;

use App\Exceptions\CloudConnectionException;
use App\Models\Business;
use App\Services\CloudApiService;
use App\Services\CloudStudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CloudRequestFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_transport_failure_identifies_the_step_without_logging_secrets_or_retrying_a_write(): void
    {
        $studio = Business::create(['name' => 'Studio', 'slug' => 'studio', 'email' => 'studio@example.test']);
        $token = str_repeat('secret', 12);
        config(['photohub.mode' => 'local', 'photohub.cloud_enabled' => true, 'photohub.cloud_url' => 'https://cloud.example',
            'photohub.business_id' => $studio->id, 'photohub.studio_token' => $token]);
        app(CloudStudioService::class)->connection($studio)->update(['status' => 'connected']);
        Log::spy();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: sensitive '.$token));
        try {
            app(CloudApiService::class)->request($studio->id, 'post', 'galleries/private-uuid/publish', ['kind' => 'preview']);
            $this->fail('Expected a transport error');
        } catch (CloudConnectionException $error) {
            $this->assertStringContainsString('Preparing the customer link timed out', $error->getMessage());
            $this->assertStringNotContainsString($token, $error->getMessage());
        }
        Log::shouldHaveReceived('warning')->once()->with('PhotoHub cloud transport failed', [
            'business_id' => $studio->id, 'step' => 'Preparing the customer link', 'curl_code' => 28, 'timeout_seconds' => 30,
        ]);
        // A failed request is not automatically replayed: the user explicitly resumes.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'register'));
        $this->assertSame('connected', $studio->cloudConnection->status);
    }
}
