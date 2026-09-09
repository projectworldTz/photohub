<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\Photo;
use App\Services\GalleryAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GalleryRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function gallery(): array
    {
        $business = Business::firstOrFail();
        $gallery = Gallery::create(['business_id' => $business->id, 'customer_id' => Customer::forBusiness($business->id)->firstOrFail()->id, 'gallery_number' => 'RATE-'.Str::random(8), 'name' => 'Selection rate test', 'code' => Str::random(20), 'type' => 'selection', 'privacy' => 'private', 'status' => 'proofs_ready']);
        $path = 'rate-test/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'preview');
        $photo = Photo::create(['business_id' => $business->id, 'gallery_id' => $gallery->id, 'uuid' => (string) Str::uuid(), 'filename' => 'test.jpg', 'original_path' => $path, 'preview_path' => $path, 'file_size' => 7, 'mime_type' => 'image/jpeg', 'status' => 'ready', 'is_proof' => true]);
        $token = app(GalleryAccessService::class)->generate($gallery, 'selection')->plainToken();

        return [$gallery, $photo, $token];
    }

    public function test_preview_requests_do_not_exhaust_selection_submission_limit(): void
    {
        $this->seed();
        [$gallery, $photo, $token] = $this->gallery();
        for ($i = 0; $i < 12; $i++) {
            $this->get(route('selection.image', [$token, $photo]))->assertOk();
        }
        $this->postJson(route('selection.toggle', [$token, $photo]))->assertOk();
        $this->post(route('selection.submit', $token))->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('selection_success', true);
        $this->assertSame('selection_submitted', $gallery->fresh()->status);
        $this->get(route('selection.show', $token))->assertOk()->assertSee('Selection sent successfully!')->assertSee('id="selection-success-toast"', false);
        $this->get(route('selection.show', $token))->assertOk()->assertDontSee('id="selection-success-toast"', false)->assertSee('Selection submitted successfully');
    }

    public function test_submission_limit_still_applies_but_does_not_block_other_actions_or_galleries(): void
    {
        $this->seed();
        [$gallery, $photo, $token] = $this->gallery();
        // Validation failures count as attempts without repeatedly completing a selection.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('selection.submit', $token))->assertUnprocessable()->assertSessionMissing('selection_success');
        }
        $this->postJson(route('selection.submit', $token))->assertTooManyRequests()->assertHeader('Retry-After');
        $this->get(route('selection.image', [$token, $photo]))->assertOk();
        $this->postJson(route('selection.toggle', [$token, $photo]))->assertOk();
        [$other, $otherPhoto, $otherToken] = $this->gallery();
        $this->postJson(route('selection.toggle', [$otherToken, $otherPhoto]))->assertOk();
        $this->post(route('selection.submit', $otherToken))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('selection_submitted', $other->fresh()->status);
        $this->travel(61)->seconds();
        $this->post(route('selection.submit', $token))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_pin_brute_force_limit_remains_enforced(): void
    {
        $this->seed();
        [$gallery, $photo, $token] = $this->gallery();
        $gallery->update(['pin_hash' => Hash::make('1234')]);
        for ($i = 0; $i < 8; $i++) {
            $this->post(route('selection.unlock', $token), ['pin' => 'wrong'])->assertRedirect()->assertSessionHasErrors('pin');
        }
        $this->post(route('selection.unlock', $token), ['pin' => '1234'])->assertTooManyRequests();
        $this->get(route('selection.image', [$token, $photo]))->assertForbidden();
    }
}
