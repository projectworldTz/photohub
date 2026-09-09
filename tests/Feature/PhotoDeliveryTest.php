<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Services\ZipDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class PhotoDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function gallery(Business $b, array $extra = []): Gallery
    {
        return Gallery::create($extra + ['business_id' => $b->id, 'gallery_number' => 'GAL-T', 'name' => 'Test Gallery', 'code' => 'TESTGAL', 'type' => 'final', 'privacy' => 'private', 'status' => 'published']);
    }

    public function test_valid_image_upload_keeps_original_and_generates_variants(): void
    {
        $this->seed();
        Storage::fake('local');
        $b = Business::first();
        $u = User::where('email', 'owner@example.com')->first();
        $g = $this->gallery($b);
        $this->actingAs($u)->withSession(['business_id' => $b->id])->post(route('galleries.upload', $g), ['photos' => [UploadedFile::fake()->image('photo.jpg', 1200, 800)], 'is_final' => 1])->assertRedirect();
        $p = $g->photos()->firstOrFail();
        Storage::disk('local')->assertExists($p->original_path);
        Storage::disk('local')->assertExists($p->preview_path);
        Storage::disk('local')->assertExists($p->thumbnail_path);
        $this->assertTrue($p->is_final);
    }

    public function test_zip_contains_only_authorized_gallery_photos(): void
    {
        $this->seed();
        Storage::fake('local');
        $b = Business::first();
        $g = $this->gallery($b, ['downloads_enabled' => true, 'payment_required' => false]);
        $photos = collect(['one.jpg', 'two.jpg'])->map(function ($name) use ($b, $g) {
            $path = 'originals/'.$name;
            Storage::disk('local')->put($path, 'image');

            return Photo::create(['business_id' => $b->id, 'gallery_id' => $g->id, 'uuid' => fake()->uuid(), 'filename' => $name, 'original_path' => $path, 'file_size' => 5, 'mime_type' => 'image/jpeg', 'status' => 'ready', 'is_downloadable' => true]);
        });
        $path = app(ZipDownloadService::class)->create($g, $photos->pluck('id')->all());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
        unlink($path);
    }

    public function test_paid_public_order_unlocks_only_its_photo_download(): void
    {
        $this->seed();
        Storage::fake('local');
        $business = Business::first();
        $gallery = $this->gallery($business, ['privacy' => 'public', 'downloads_enabled' => true, 'payment_required' => true]);
        $make = function (string $name) use ($business, $gallery): Photo {
            $path = 'originals/'.$name;
            Storage::disk('local')->put($path, 'image');

            return Photo::create(['business_id' => $business->id, 'gallery_id' => $gallery->id, 'uuid' => fake()->uuid(), 'filename' => $name, 'original_path' => $path, 'file_size' => 5, 'mime_type' => 'image/jpeg', 'status' => 'ready', 'is_downloadable' => true]);
        };
        $paid = $make('paid.jpg');
        $unpaid = $make('unpaid.jpg');
        $order = Order::create(['business_id' => $business->id, 'order_number' => 'ORD-PAID', 'gallery_id' => $gallery->id, 'subtotal' => 10, 'total' => 10, 'payment_status' => 'paid', 'status' => 'completed']);
        $order->items()->create(['photo_id' => $paid->id, 'price' => 10]);

        $this->get(route('public.gallery.download', [$gallery->code, $paid]))->assertOk();
        $this->get(route('public.gallery.download', [$gallery->code, $unpaid]))->assertStatus(402);
    }
}
