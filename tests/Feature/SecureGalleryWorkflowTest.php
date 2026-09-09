<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Services\GalleryAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecureGalleryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupGallery(): array
    {
        $this->seed();
        Storage::fake('local');
        $business = Business::first();
        $customer = Customer::first();
        $gallery = Gallery::create(['business_id' => $business->id, 'gallery_number' => 'GAL-TOKEN', 'name' => 'Secure Wedding Proofs', 'code' => 'LEGACY-CODE', 'customer_id' => $customer->id, 'type' => 'selection', 'privacy' => 'private', 'selection_limit' => 2, 'status' => 'selection_link_ready']);
        $photos = collect(range(1, 3))->map(function ($number) use ($gallery) {
            $path = "proofs/{$number}.jpg";
            Storage::disk('local')->put($path, 'photo-'.$number);

            return Photo::create(['business_id' => $gallery->business_id, 'gallery_id' => $gallery->id, 'uuid' => (string) Str::uuid(), 'filename' => "DSC_{$number}.jpg", 'original_path' => $path, 'preview_path' => $path, 'thumbnail_path' => $path, 'file_size' => 7, 'mime_type' => 'image/jpeg', 'status' => 'ready', 'is_proof' => true]);
        });
        $token = app(GalleryAccessService::class)->generate($gallery, 'selection');

        return compact('business', 'customer', 'gallery', 'photos', 'token');
    }

    public function test_guest_selection_persists_and_locks_without_customer_login(): void
    {
        extract($this->setupGallery());
        $plain = $token->plainToken();
        $this->get(route('selection.show', $plain))->assertOk()->assertSee('Secure Wedding Proofs');
        $this->postJson(route('selection.toggle', [$plain, $photos[0]]))->assertOk()->assertJson(['selected' => true, 'count' => 1]);
        $this->get(route('selection.show', $plain))->assertSee('Selected: 1 / 2');
        $this->post(route('selection.submit', $plain))->assertRedirect();
        $this->assertSame('selection_submitted', $gallery->fresh()->status);
        $this->postJson(route('selection.toggle', [$plain, $photos[1]]))->assertStatus(422);
    }

    public function test_invitation_has_event_branding_and_keeps_pin_protected_photos_private(): void
    {
        extract($this->setupGallery());
        $gallery->update(['event' => 'Wedding celebration', 'event_date' => '2026-10-10']);
        $plain = $token->plainToken();
        $this->get(route('selection.show', $plain))->assertOk()
            ->assertSee($business->name)->assertSee('Wedding celebration')->assertSee('10 October 2026')
            ->assertSee('Choose your photos')->assertSee('property="og:title"', false)
            ->assertSee('class="invitation-cover"', false);

        $gallery->update(['pin_hash' => \Illuminate\Support\Facades\Hash::make('1234')]);
        $this->get(route('selection.show', $plain))->assertOk()
            ->assertSee('Your private collection')->assertSee($gallery->name)->assertSee($business->name)
            ->assertDontSee('class="invitation-cover"', false)
            ->assertDontSee(route('selection.image', [$plain, $photos[0]]), false);
        $this->get(route('selection.image', [$plain, $photos[0]]))->assertForbidden();
        $this->post(route('selection.unlock', $plain), ['pin' => '1234'])->assertRedirect();
        $this->get(route('selection.show', $plain))->assertOk()->assertSee('Choose your photos');

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->actingAs($owner)->withSession(['business_id' => $business->id])
            ->get(route('galleries.workflow', $gallery))->assertOk()
            ->assertSee('Copy invitation')->assertSee('WhatsApp invitation')
            ->assertSee($business->name.' invites you to '.$gallery->name.'.');
    }

    public function test_expired_revoked_and_wrong_tokens_never_expose_gallery(): void
    {
        extract($this->setupGallery());
        $plain = $token->plainToken();
        $token->update(['expires_at' => now()->subMinute()]);
        $this->get(route('selection.show', $plain))->assertOk()->assertSee('expired');
        $fresh = app(GalleryAccessService::class)->generate($gallery, 'selection');
        $fresh->update(['revoked_at' => now()]);
        $this->get(route('selection.show', $fresh->plainToken()))->assertOk()->assertSee('unavailable');
        $this->get(route('selection.show', Str::random(48)))->assertOk()->assertSee('unavailable');
        $this->get(route('selection.image', [$plain, $photos[0]]))->assertForbidden();
    }

    public function test_photographer_reopens_and_publishes_separate_final_delivery(): void
    {
        extract($this->setupGallery());
        $plain = $token->plainToken();
        $this->postJson(route('selection.toggle', [$plain, $photos[0]]));
        $this->post(route('selection.submit', $plain));
        $owner = User::where('email', 'owner@example.com')->first();
        $this->actingAs($owner)->withSession(['business_id' => $business->id])->post(route('galleries.selection.reopen', $gallery))->assertRedirect();
        $this->assertSame('selection_reopened', $gallery->fresh()->status);
        $this->postJson(route('selection.toggle', [$plain, $photos[1]]))->assertOk();
        $this->post(route('selection.submit', $plain));
        $file = UploadedFile::fake()->image('DSC_1.jpg', 800, 600);
        $this->actingAs($owner)->withSession(['business_id' => $business->id])->post(route('galleries.finals.upload', $gallery), ['finals' => [$file]])->assertRedirect();
        $this->actingAs($owner)->withSession(['business_id' => $business->id])->post(route('galleries.finals.publish', $gallery), ['confirm_incomplete' => 1])->assertRedirect();
        $delivery = $gallery->accessTokens()->where('purpose', 'final_delivery')->first();
        $this->assertNotNull($delivery);
        $this->assertNotSame($plain, $delivery->plainToken());
        $this->get(route('delivery.show', $delivery->plainToken()))->assertOk()->assertSee('Your Photos Are Ready');
        $final = $gallery->finalPhotos()->first();
        $this->get(route('delivery.download', [$delivery->plainToken(), $final]))->assertDownload('DSC_1.jpg');
    }

    public function test_studio_can_deliver_finished_photos_without_a_selection_link(): void
    {
        $this->seed();
        $business = Business::firstOrFail();
        $customer = Customer::firstOrFail();
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->actingAs($owner)->withSession(['business_id' => $business->id]);
        $this->post(route('galleries.store'), [
            'name' => 'Direct wedding delivery', 'customer_id' => $customer->id,
            'type' => 'final_delivery', 'privacy' => 'private', 'status' => 'draft',
        ])->assertRedirect();
        $gallery = Gallery::where('name', 'Direct wedding delivery')->firstOrFail();
        $this->get(route('galleries.workflow', $gallery))->assertOk()
            ->assertSee('No selection link is required.')->assertDontSee('Customer selection');

        $this->post(route('galleries.finals.publish', $gallery))->assertSessionHasErrors('finals');
        $this->assertSame('draft', $gallery->fresh()->status);
        $this->assertSame(0, $gallery->accessTokens()->count());

        $this->post(route('galleries.finals.upload', $gallery), [
            'finals' => [UploadedFile::fake()->image('wedding.jpg', 800, 600)],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('final_ready', $gallery->fresh()->status);
        $this->get(route('galleries.workflow', $gallery))->assertOk()->assertSee('Create download link')->assertDontSee('Unmatched');
        $this->post(route('galleries.finals.publish', $gallery))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($gallery->accessTokens()->where('purpose', 'selection')->exists());
        $this->assertNull($gallery->fresh()->selection_completed_at);
        $token = $gallery->accessTokens()->where('purpose', 'final_delivery')->firstOrFail();
        $this->post(route('logout'));
        $this->get(route('delivery.show', $token->plainToken()))->assertOk()->assertSee('Direct wedding delivery');
        $this->get(route('delivery.download', [$token->plainToken(), $gallery->finalPhotos()->firstOrFail()]))->assertDownload('wedding.jpg');
    }
}
