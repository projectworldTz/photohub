<?php
namespace Tests\Feature;

use App\Models\Business;
use App\Services\CloudStudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudioCloudConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function studio(): Business
    {
        return Business::create(['name' => 'Offline studio', 'slug' => Str::uuid(), 'email' => Str::uuid().'@example.test', 'currency' => 'TZS']);
    }

    public function test_cloud_registration_is_lightweight_idempotent_and_revocation_cannot_be_bypassed(): void
    {
        config(['photohub.allow_http' => true, 'photohub.registration_enabled' => true]);
        $data = ['studio_uuid' => (string) Str::uuid(), 'registration_secret' => Str::random(64), 'name' => 'Photo studio'];
        $first = $this->postJson('/api/share/v1/studios/register', $data)->assertOk()->json();
        $this->postJson('/api/share/v1/studios/register', $data)->assertOk()->assertJson($first);
        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('studio_api_tokens', 1);
        $this->assertSame(hash('sha256', $first['studio_token']), DB::table('studio_api_tokens')->value('token_hash'));
        $this->postJson('/api/share/v1/studios/register', array_replace($data, ['registration_secret' => Str::random(64)]))->assertForbidden();
        DB::table('studio_api_tokens')->update(['revoked_at' => now()]);
        $this->postJson('/api/share/v1/studios/register', $data)->assertForbidden();
    }

    public function test_existing_and_future_studios_get_isolated_encrypted_connections_and_register_only_on_demand(): void
    {
        $existing = $this->studio();
        config(['photohub.mode' => 'local', 'photohub.cloud_enabled' => true, 'photohub.cloud_url' => 'https://cloud.example', 'photohub.business_id' => $existing->id, 'photohub.studio_token' => str_repeat('L', 64)]);
        $service = app(CloudStudioService::class);
        Http::preventStrayRequests();
        $legacy = $service->connection($existing);
        $future = $this->studio();
        $new = $service->connection($future);
        Http::assertNothingSent();
        $this->assertSame(str_repeat('L', 64), $legacy->api_token);
        $this->assertNull($new->api_token);
        $this->assertNotSame($legacy->identity_uuid, $new->identity_uuid);
        $this->assertArrayNotHasKey('api_token', $legacy->toArray());
        $this->assertNotSame($legacy->api_token, DB::table('studio_cloud_connections')->where('id', $legacy->id)->value('api_token'));
        Http::fake([
            '*/studios/register' => Http::response(['cloud_studio_id' => 27, 'studio_token' => str_repeat('N', 64)]),
            '*/health' => Http::response(['connected' => true, 'cloud_studio_id' => 27]),
        ]);
        $connected = $service->ensure($future);
        $service->ensure($future);
        Http::assertSentCount(2);
        $this->assertSame('connected', $connected->status);
        $this->assertSame(str_repeat('L', 64), $legacy->fresh()->api_token);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/studios/register') && ! isset($r['email']) && ! isset($r['customers']) && $r['studio_uuid'] === $new->identity_uuid);
    }

    public function test_failed_registration_preserves_local_studio_and_never_marks_connected(): void
    {
        config(['photohub.mode' => 'local', 'photohub.cloud_enabled' => true, 'photohub.cloud_url' => 'https://cloud.example', 'photohub.studio_token' => null]);
        $studio = $this->studio();
        Http::fake(['*' => Http::response([], 503)]);
        try { app(CloudStudioService::class)->ensure($studio); $this->fail('Expected connection error'); }
        catch (\App\Exceptions\CloudConnectionException $e) { $this->assertStringContainsString('local work is safe', $e->getMessage()); }
        $this->assertDatabaseHas('businesses', ['id' => $studio->id, 'name' => 'Offline studio']);
        $this->assertDatabaseHas('studio_cloud_connections', ['business_id' => $studio->id, 'status' => 'failed', 'api_token' => null]);
    }
    public function test_legacy_credentials_are_reused_and_mismatched_cloud_identity_is_rejected(): void
    {
        $studio = $this->studio();
        config(['photohub.mode' => 'local', 'photohub.cloud_enabled' => true, 'photohub.cloud_url' => 'https://cloud.example', 'photohub.business_id' => $studio->id, 'photohub.studio_token' => str_repeat('L', 64)]);
        Http::fake(['*/health' => Http::response(['connected' => true, 'cloud_studio_id' => 91])]);
        $service = app(CloudStudioService::class);
        $connection = $service->ensure($studio);
        $service->ensure($studio);
        Http::assertSentCount(1);
        $this->assertSame(91, $connection->cloud_studio_id);
        $this->assertSame(str_repeat('L', 64), $connection->api_token);
        $this->expectException(\App\Exceptions\CloudConnectionException::class);
        $service->acceptHealth($connection, ['connected' => true, 'cloud_studio_id' => 92]);
    }

    public function test_settings_hide_credentials_and_test_only_the_current_studio(): void
    {
        $this->seed();
        $studio = Business::first();
        config(['photohub.mode' => 'local', 'photohub.cloud_enabled' => true, 'photohub.cloud_url' => 'https://cloud.example', 'photohub.business_id' => $studio->id, 'photohub.studio_token' => str_repeat('L', 64)]);
        Http::preventStrayRequests();
        $this->actingAs(\App\Models\User::where('email', 'owner@example.com')->first())->withSession(['business_id' => $studio->id]);
        $this->get(route('settings.index'))->assertOk()->assertSee('Cloud Connection')->assertDontSee(str_repeat('L', 64));
        Http::assertNothingSent();
        Http::fake(['*/health' => Http::response(['connected' => true, 'cloud_studio_id' => 91])]);
        $this->post(route('settings.cloud.test'), ['base_url' => 'https://cloud.example'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('studio_cloud_connections', ['business_id' => $studio->id, 'status' => 'connected', 'cloud_studio_id' => 91]);
        $replacement = str_repeat('R', 64);
        $this->post(route('settings.cloud.test'), ['base_url' => 'https://different.example', 'cloud_api_token' => $replacement])->assertSessionHasErrors('cloud_connection')->assertSessionMissing('_old_input.cloud_api_token');
        $this->assertSame(str_repeat('L', 64), $studio->cloudConnection->api_token);
    }

}
