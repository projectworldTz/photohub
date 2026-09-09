<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryPricingTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $business = Business::where('slug', 'lenscraft-studio')->firstOrFail();
        $this->actingAs(User::where('email', 'owner@example.com')->firstOrFail())->withSession(['business_id' => $business->id]);
        $this->payload = ['name' => 'Anniversary pricing test', 'customer_id' => Customer::forBusiness($business->id)->firstOrFail()->id, 'type' => 'selection', 'privacy' => 'pin', 'pin' => '1234', 'status' => 'draft', 'selection_limit' => 5, 'require_exact_selection' => 1];
    }

    private function createdGallery(): Gallery
    {
        return Gallery::where('name', $this->payload['name'])->firstOrFail();
    }

    public function test_create_with_blank_public_price_keeps_extra_photo_price(): void
    {
        $this->post(route('galleries.store'), $this->payload + ['photo_price' => '', 'extra_photo_price' => '3000'])->assertRedirect()->assertSessionHasNoErrors();
        $gallery = $this->createdGallery();
        $this->assertEquals(0, $gallery->photo_price);
        $this->assertEquals(3000, $gallery->extra_photo_price);
        $this->assertNotNull($gallery->pin_hash);
    }

    public function test_create_accepts_blank_or_omitted_prices(): void
    {
        $this->post(route('galleries.store'), $this->payload + ['photo_price' => null, 'extra_photo_price' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->createdGallery()->photo_price);
        $this->assertEquals(0, $this->createdGallery()->extra_photo_price);
        $this->payload['name'] = 'Prices omitted';
        $this->post(route('galleries.store'), $this->payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->createdGallery()->photo_price);
        $this->assertEquals(0, $this->createdGallery()->extra_photo_price);
    }

    public function test_edit_preserves_omitted_prices_and_clears_explicitly_blank_prices(): void
    {
        $this->post(route('galleries.store'), $this->payload + ['photo_price' => '1500.50', 'extra_photo_price' => '3000'])->assertSessionHasNoErrors();
        $gallery = $this->createdGallery();
        $this->put(route('galleries.update', $gallery), $this->payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(1500.50, $gallery->fresh()->photo_price);
        $this->assertEquals(3000, $gallery->fresh()->extra_photo_price);
        $this->put(route('galleries.update', $gallery), $this->payload + ['photo_price' => '', 'extra_photo_price' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(0, $gallery->fresh()->photo_price);
        $this->assertEquals(0, $gallery->fresh()->extra_photo_price);
    }

    public function test_invalid_prices_still_produce_validation_errors(): void
    {
        $this->post(route('galleries.store'), $this->payload + ['photo_price' => '-1', 'extra_photo_price' => 'not a price'])->assertSessionHasErrors(['photo_price', 'extra_photo_price']);
        $this->assertDatabaseMissing('galleries', ['name' => $this->payload['name']]);
    }
}
