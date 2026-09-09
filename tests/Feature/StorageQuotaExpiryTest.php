<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\User;
use App\Notifications\BusinessAlert;
use App\Services\FinalPhotoService;
use App\Services\GalleryAccessService;
use App\Services\GalleryExpiryService;
use App\Services\PhotoProcessingService;
use App\Services\StorageQuotaService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StorageQuotaExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    private function studio(): Business
    {
        return Business::firstOrFail();
    }

    private function gallery(array $attributes = []): Gallery
    {
        return Gallery::create($attributes + ['business_id' => $this->studio()->id, 'customer_id' => Customer::first()->id, 'gallery_number' => Str::random(12), 'name' => 'Quota gallery', 'code' => Str::random(12), 'type' => 'selection', 'privacy' => 'public', 'status' => 'published', 'downloads_enabled' => true]);
    }

    private function allocateUsage(Business $business, int $bytes): void
    {
        app(StorageQuotaService::class)->record($business->id, 'businesses/'.$business->id.'/test-existing.jpg', $bytes);
    }

    public function test_new_studio_defaults_to_three_gb_and_mass_assignment_cannot_change_it(): void
    {
        $business = Business::create(['name' => 'Second', 'slug' => 'second', 'email' => 'second@example.com', 'storage_limit_bytes' => 99]);
        $this->assertSame(3 * StorageQuotaService::GB, $business->fresh()->storage_limit_bytes);
        $business->update(['storage_limit_bytes' => 1]);
        $this->assertSame(3 * StorageQuotaService::GB, $business->fresh()->storage_limit_bytes);
    }

    public function test_two_gb_plus_five_hundred_mb_fits(): void
    {
        $business = $this->studio();
        $this->allocateUsage($business, 2 * StorageQuotaService::GB);
        app(StorageQuotaService::class)->assertFits($business, 500 * 1048576);
        $this->assertSame(StorageQuotaService::GB, app(StorageQuotaService::class)->usage($business)['remaining']);
    }

    public function test_two_point_nine_gb_plus_three_hundred_mb_is_rejected(): void
    {
        $business = $this->studio();
        $this->allocateUsage($business, (int) (2.9 * StorageQuotaService::GB));
        $this->expectException(ValidationException::class);
        app(StorageQuotaService::class)->assertFits($business, 300 * 1048576);
    }

    public function test_super_admin_allocation_is_isolated_and_owner_gets_403(): void
    {
        $a = $this->studio();
        $b = Business::create(['name' => 'Other studio', 'slug' => 'other', 'email' => 'other@example.com']);
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->actingAs($owner)->withSession(['business_id' => $a->id])->patch(route('admin.businesses.storage', $a), ['storage_limit_gb' => 10])->assertForbidden();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->patch(route('admin.businesses.storage', $a), ['storage_limit_gb' => 10])->assertRedirect();
        $this->assertSame(10 * StorageQuotaService::GB, $a->fresh()->storage_limit_bytes);
        $this->assertSame(3 * StorageQuotaService::GB, $b->fresh()->storage_limit_bytes);
        $this->get(route('admin.businesses.show', $a))->assertOk()->assertSee('Storage Allocation');
        $this->get(route('admin.index'))->assertOk()->assertSee('10 GB');
        $this->patchJson(route('admin.businesses.storage', $a), ['storage_limit_gb' => 0])->assertUnprocessable();
        $this->patchJson(route('admin.businesses.storage', $a), ['storage_limit_gb' => 1.5])->assertUnprocessable();
    }

    public function test_actual_originals_and_variants_are_counted_once(): void
    {
        $gallery = $this->gallery();
        $photos = app(PhotoProcessingService::class)->storeBatch($gallery, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]);
        $final = app(FinalPhotoService::class)->store($gallery, UploadedFile::fake()->image('edited.jpg'));
        $files = Storage::disk('local')->allFiles('businesses/'.$gallery->business_id);
        $this->assertCount(9, $files);
        $bytes = array_sum(array_map(fn ($path) => Storage::disk('local')->size($path), $files));
        $this->assertSame($bytes, app(StorageQuotaService::class)->usage($this->studio())['used']);
        $photos[0]->delete();
        $final->delete();
        $gallery->delete();
        $this->assertSame($bytes, app(StorageQuotaService::class)->usage($this->studio())['used']);
        app(StorageQuotaService::class)->reconcile($this->studio());
        $this->assertSame($bytes, app(StorageQuotaService::class)->usage($this->studio())['used']);
    }

    public function test_variant_overhead_rejects_the_entire_batch_without_files(): void
    {
        $gallery = $this->gallery();
        $a = UploadedFile::fake()->image('a.jpg');
        $b = UploadedFile::fake()->image('b.jpg');
        $this->studio()->forceFill(['storage_limit_bytes' => $a->getSize() + $b->getSize()])->save();
        try {
            app(PhotoProcessingService::class)->storeBatch($gallery, [$a, $b]);
            $this->fail('Expected rejection for variant bytes.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Storage limit reached', $exception->getMessage());
        }
        $this->assertSame(0, $gallery->photos()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, app(StorageQuotaService::class)->usage($this->studio())['used']);
    }

    public function test_final_upload_endpoint_cannot_bypass_quota(): void
    {
        $gallery = $this->gallery();
        $this->allocateUsage($this->studio(), 3 * StorageQuotaService::GB);
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->actingAs($owner)->withSession(['business_id' => $gallery->business_id])->postJson(route('galleries.finals.upload', $gallery), ['finals' => [UploadedFile::fake()->image('final.jpg')]])->assertUnprocessable();
        $this->assertSame(0, $gallery->finalPhotos()->count());
        $this->assertSame('published', $gallery->fresh()->status);
    }

    public function test_default_expiry_blocks_every_public_link_but_keeps_owner_and_files(): void
    {
        $this->travelTo(now()->startOfSecond());
        $gallery = $this->gallery();
        $this->assertTrue($gallery->expires_at->equalTo($gallery->created_at->copy()->addDays(30)));
        $photo = app(PhotoProcessingService::class)->store($gallery, UploadedFile::fake()->image('photo.jpg'), true);
        $final = app(FinalPhotoService::class)->store($gallery, UploadedFile::fake()->image('edited.jpg'));
        $access = app(GalleryAccessService::class);
        $selection = $access->generate($gallery, 'selection', now()->addYear());
        $delivery = $access->generate($gallery, 'final_delivery', now()->addYear());
        $gallery->update(['status' => 'final_published']);
        $before = app(StorageQuotaService::class)->usage($this->studio())['used'];
        $this->travelTo($gallery->expires_at);
        app(GalleryExpiryService::class)->expire();
        $this->assertNotNull($gallery->fresh()->expired_at);
        $this->assertSame('final_published', $gallery->fresh()->status);
        $this->get(route('selection.show', $selection->plainToken()))->assertSee('expired')->assertDontSee('Quota gallery');
        $this->get(route('delivery.show', $delivery->plainToken()))->assertSee('expired')->assertDontSee('Your Photos Are Ready');
        $this->get(route('delivery.download', [$delivery->plainToken(), $final]))->assertForbidden();
        $this->get(route('public.gallery.photo', [$gallery->code, $photo]))->assertGone();
        $this->get(route('selection.image', [$selection->plainToken(), $photo]))->assertForbidden();
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->actingAs($owner)->withSession(['business_id' => $gallery->business_id])->get(route('galleries.show', $gallery))->assertOk()->assertSee('This gallery has expired.');
        Storage::disk('local')->assertExists($photo->original_path);
        $this->assertSame($before, app(StorageQuotaService::class)->usage($this->studio())['used']);
    }

    public function test_reconciliation_protects_an_eight_gb_legacy_studio_without_touching_files(): void
    {
        $business = $this->studio();
        $business->forceFill(['storage_limit_bytes' => null])->save();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $path = 'businesses/'.$business->id.'/original.jpg';
        $disk->shouldReceive('allFiles')->with('businesses/'.$business->id)->andReturn([$path]);
        $disk->shouldReceive('exists')->with($path)->andReturn(true);
        $disk->shouldReceive('exists')->with('businesses/'.$business->id.'/galleries/1/originals/demo-photo.jpg')->andReturn(false);
        $disk->shouldReceive('size')->with($path)->andReturn(8 * StorageQuotaService::GB + 1);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        app(StorageQuotaService::class)->reconcile($business);
        $this->assertSame(9 * StorageQuotaService::GB, $business->fresh()->storage_limit_bytes);
        $this->assertSame(8 * StorageQuotaService::GB + 1, app(StorageQuotaService::class)->usage($business->fresh())['used']);
        app(StorageQuotaService::class)->reconcile($business);
        $this->assertSame(9 * StorageQuotaService::GB, $business->fresh()->storage_limit_bytes);
    }

    public function test_successful_physical_deletion_releases_only_its_bytes(): void
    {
        $gallery = $this->gallery();
        $photo = app(PhotoProcessingService::class)->store($gallery, UploadedFile::fake()->image('a.jpg'));
        $quota = app(StorageQuotaService::class);
        $before = $quota->usage($this->studio())['used'];
        $bytes = Storage::disk('local')->size($photo->original_path);
        $gallery->update(['expires_at' => now()->subDay()]);
        $quota->deleteFile($this->studio(), $photo->original_path);
        Storage::disk('local')->assertMissing($photo->original_path);
        $this->assertSame($before - $bytes, $quota->usage($this->studio())['used']);
    }

    public function test_failed_deletion_retains_inventory(): void
    {
        $business = $this->studio();
        $path = 'businesses/'.$business->id.'/blocked.jpg';
        $quota = app(StorageQuotaService::class);
        $quota->record($business->id, $path, 123);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with($path)->andReturn(true);
        $disk->shouldReceive('delete')->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            $quota->deleteFile($business, $path);
            $this->fail('Expected deletion failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('could not be deleted', $exception->getMessage());
        }
        $this->assertSame(123, $quota->usage($business)['used']);
    }

    public function test_foreign_paths_are_never_deleted_or_counted(): void
    {
        $business = $this->studio();
        $other = Business::create(['name' => 'Other', 'slug' => 'other', 'email' => 'other@example.com']);
        $path = 'businesses/'.$other->id.'/photo.jpg';
        Storage::disk('local')->put($path, 'foreign');
        app(StorageQuotaService::class)->reconcile($business);
        $this->assertSame(0, app(StorageQuotaService::class)->usage($business)['used']);
        $this->expectException(\RuntimeException::class);
        app(StorageQuotaService::class)->deleteFile($business, $path);
    }

    public function test_expiry_reminders_are_deduplicated_and_extension_updates_matching_links(): void
    {
        Notification::fake();
        Gallery::query()->update(['expires_at' => now()->addYear()]);
        $gallery = $this->gallery();
        $token = app(GalleryAccessService::class)->generate($gallery, 'selection');
        $this->travelTo($gallery->expires_at->copy()->subDays(7));
        app(GalleryExpiryService::class)->remind();
        $this->assertSame(7, (int) $gallery->fresh()->expiry_notice_days);
        app(GalleryExpiryService::class)->remind();
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        Notification::assertSentToTimes($owner, BusinessAlert::class, 1);
        foreach ([3, 1] as $days) {
            $this->travelTo($gallery->expires_at->copy()->subDays($days));
            app(GalleryExpiryService::class)->remind();
            $this->assertSame($days, (int) $gallery->fresh()->expiry_notice_days);
        }
        Notification::assertSentToTimes($owner, BusinessAlert::class, 3);
        $previous = $gallery->expires_at->copy();
        $this->actingAs($owner)->withSession(['business_id' => $gallery->business_id])->post(route('galleries.extend', $gallery), ['days' => '30'])->assertRedirect();
        $this->assertTrue($gallery->fresh()->expires_at->equalTo($previous->addDays(30)));
        $this->assertTrue($token->fresh()->expires_at->equalTo($gallery->fresh()->expires_at));
        $this->assertNull($gallery->fresh()->expiry_notice_days);
    }

    public function test_replacing_a_final_retains_the_old_files_and_their_storage(): void
    {
        $gallery = $this->gallery();
        $proof = app(PhotoProcessingService::class)->store($gallery, UploadedFile::fake()->image('proof.jpg'));
        $service = app(FinalPhotoService::class);
        $old = $service->store($gallery, UploadedFile::fake()->image('edit.jpg'), $proof->id);
        $before = app(StorageQuotaService::class)->usage($this->studio())['used'];
        $new = $service->store($gallery, UploadedFile::fake()->image('edit-v2.jpg'), $proof->id);
        $this->assertSoftDeleted('final_photos', ['id' => $old->id]);
        Storage::disk('local')->assertExists([$old->original_path, $old->preview_path, $old->thumbnail_path]);
        $this->assertSame(1, $gallery->finalPhotos()->count());
        $newBytes = array_sum(array_map(fn ($path) => Storage::disk('local')->size($path), [$new->original_path, $new->preview_path, $new->thumbnail_path]));
        $this->assertSame($before + $newBytes, app(StorageQuotaService::class)->usage($this->studio())['used']);
    }

    public function test_a_disk_failure_rolls_back_the_whole_upload_batch(): void
    {
        $gallery = $this->gallery();
        $disk = Storage::disk('local');
        $failing = \Mockery::mock(FilesystemAdapter::class);
        foreach (['exists', 'size', 'delete', 'allFiles'] as $method) {
            $failing->shouldReceive($method)->andReturnUsing(fn (...$args) => $disk->{$method}(...$args));
        }
        $writes = 0;
        $failing->shouldReceive('put')->andReturnUsing(function (...$args) use ($disk, &$writes) {
            return ++$writes === 4 ? false : $disk->put(...$args);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($failing);
        try {
            app(PhotoProcessingService::class)->storeBatch($gallery, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]);
            $this->fail('Expected disk failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unable to save', $exception->getMessage());
        }
        $this->assertSame(0, $gallery->photos()->count());
        $this->assertSame([], $disk->allFiles());
        $this->assertSame(0, app(StorageQuotaService::class)->usage($this->studio())['used']);
    }
}
