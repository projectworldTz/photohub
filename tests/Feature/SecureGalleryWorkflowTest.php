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
}
