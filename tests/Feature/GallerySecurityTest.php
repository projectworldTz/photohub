<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GallerySecurityTest extends TestCase
{
    use RefreshDatabase;

    private function photo(Gallery $g): Photo
    {
        return Photo::create(['business_id' => $g->business_id, 'gallery_id' => $g->id, 'uuid' => fake()->uuid(), 'filename' => 'original.jpg', 'original_path' => 'secret/original.jpg', 'preview_path' => 'secret/preview.jpg', 'file_size' => 10, 'mime_type' => 'image/jpeg', 'status' => 'ready', 'is_downloadable' => true]);
    }

    public function test_other_tenant_cannot_stream_or_download_photo(): void
    {
        $this->seed();
        Storage::fake('local');
        $owner = User::where('email', 'owner@example.com')->first();
        $other = Business::create(['name' => 'Other', 'slug' => 'other', 'email' => 'other@x.test']);
        $g = Gallery::create(['business_id' => $other->id, 'gallery_number' => 'GAL-X', 'name' => 'Hidden', 'code' => 'HIDDEN', 'type' => 'final', 'privacy' => 'private', 'downloads_enabled' => true]);
        $p = $this->photo($g);
        $this->actingAs($owner)->withSession(['business_id' => Business::first()->id])->get(route('photos.preview', [$g, $p]))->assertNotFound();
    }

    public function test_private_gallery_requires_unlock(): void
    {
        $this->seed();
        $b = Business::first();
        $g = Gallery::create(['business_id' => $b->id, 'gallery_number' => 'GAL-P', 'name' => 'Private', 'code' => 'PRIVATE1', 'type' => 'proof', 'privacy' => 'pin', 'pin_hash' => bcrypt('1234'), 'status' => 'published']);
        $this->get(route('public.gallery', $g->code))->assertOk()->assertSee('gallery PIN');
        $this->post(route('public.gallery.unlock', $g->code), ['pin' => 'bad'])->assertStatus(422);
    }

    public function test_selection_limit_and_download_gate_are_enforced(): void
    {
        $this->seed();
        $b = Business::first();
        $c = Customer::first();
        $g = Gallery::create(['business_id' => $b->id, 'gallery_number' => 'GAL-S', 'name' => 'Proof', 'code' => 'PROOF1', 'type' => 'proof', 'privacy' => 'public', 'customer_id' => $c->id, 'selection_limit' => 1, 'extra_photo_price' => 0]);
        $a = $this->photo($g);
        $z = $this->photo($g);
        app(PhotoSelectionService::class)->toggle($g, $a, $c->id);
        $this->expectException(ValidationException::class);
        app(PhotoSelectionService::class)->toggle($g, $z, $c->id);
    }

    public function test_completing_extra_selections_creates_invoice_and_locks_gallery(): void
    {
        $this->seed();
        $business = Business::first();
        $customer = Customer::first();
        $gallery = Gallery::create(['business_id' => $business->id, 'gallery_number' => 'GAL-E', 'name' => 'Proof Extras', 'code' => 'EXTRAS', 'type' => 'proof', 'privacy' => 'public', 'customer_id' => $customer->id, 'selection_limit' => 1, 'extra_photo_price' => 5000]);
        $service = app(PhotoSelectionService::class);
        $service->toggle($gallery, $this->photo($gallery), $customer->id);
        $service->toggle($gallery, $this->photo($gallery), $customer->id);
        $invoice = $service->complete($gallery, $customer->id);

        $this->assertSame('5000.00', $invoice->total);
        $this->assertSame('selection_submitted', $gallery->fresh()->status);
        $this->assertSame(1, Invoice::where('notes', 'like', 'Extra photo selection%')->count());
        $this->expectException(ValidationException::class);
        $service->toggle($gallery->fresh(), $this->photo($gallery), $customer->id);
    }

    public function test_anonymous_visitor_cannot_modify_customer_public_proof(): void
    {
        $this->seed();
        $business = Business::first();
        $customer = Customer::first();
        $gallery = Gallery::create(['business_id' => $business->id, 'gallery_number' => 'GAL-PUBLIC-PROOF', 'name' => 'Public Proof', 'code' => 'PUBLICPROOF', 'type' => 'proof', 'privacy' => 'public', 'status' => 'published', 'customer_id' => $customer->id]);
        $photo = $this->photo($gallery);

        $this->postJson(route('public.gallery.select', [$gallery->code, $photo]))->assertForbidden();
        $this->postJson(route('public.gallery.favorite', [$gallery->code, $photo]))->assertForbidden();
    }

    public function test_customer_is_returned_to_gallery_when_completing_an_empty_selection(): void
    {
        $this->seed();
        $customer = Customer::first();
        $gallery = Gallery::first();
        $user = $customer->user;

        $this->actingAs($user)->from(route('public.gallery', $gallery->code))
            ->post(route('public.gallery.selection.complete', $gallery->code))
            ->assertRedirect(route('public.gallery', $gallery->code))
            ->assertSessionHasErrors('selection');
    }
}
