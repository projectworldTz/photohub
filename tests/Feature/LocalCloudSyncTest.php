<?php

namespace Tests\Feature;

use App\Exceptions\SelectionSyncConflict;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\User;
use App\Services\GalleryAccessService;
use App\Services\GallerySyncService;
use App\Services\PhotoUploadService;
use App\Services\SelectionSyncService;
use App\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalCloudSyncTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->business = Business::first();
        Storage::fake('photohub_local');
        Http::preventStrayRequests();
    }

    private function gallery(): Gallery
    {
        return Gallery::create(['business_id' => $this->business->id, 'customer_id' => Customer::where('business_id', $this->business->id)->firstOrFail()->id, 'gallery_number' => 'LOCAL-'.Str::random(8), 'code' => Str::random(48), 'name' => 'Local gallery', 'type' => 'proof', 'status' => 'proofs_ready', 'watermark_enabled' => false, 'downloads_enabled' => true]);
    }

    private function local(): void
    {
        config(['photohub.mode' => 'local', 'photohub.business_id' => $this->business->id, 'photohub.cloud_enabled' => true, 'photohub.cloud_url' => 'https://cloud.example', 'photohub.studio_token' => str_repeat('x', 64)]);
    }

    public function test_local_upload_and_pages_work_without_cloud_and_ignore_cloud_quota(): void
    {
        $this->local();
        config(['photohub.cloud_enabled' => false]);
        $this->business->forceFill(['storage_limit_bytes' => 0])->save();
        $gallery = $this->gallery();
        $photo = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('original.jpg', 2000, 1200)])[0];
        $this->assertStringStartsWith('studios/'.$this->business->id.'/', $photo->original_path);
        Storage::disk('photohub_local')->assertExists($photo->original_path);
        $this->assertDatabaseMissing('storage_files', ['path' => $photo->original_path]);
        $this->actingAs(User::where('email', 'owner@example.com')->first())->withSession(['business_id' => $this->business->id])->get(route('galleries.show', $gallery))->assertOk()->assertSee('Publish Online');
        $this->get(route('photos.preview', [$gallery, $photo]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        Http::assertNothingSent();
    }

    public function test_publish_uploads_preview_not_original_and_failed_retry_is_durable(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $photo = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('original.jpg', 2000, 1000)])[0];
        $sync = app(GallerySyncService::class);
        $job = $sync->enqueue($gallery, 'publish');
        Http::fake(['*' => Http::response([], 503)]);
        $sync->process($job);
        $this->assertSame('failed', $job->fresh()->status);
        Storage::disk('photohub_local')->assertExists($photo->original_path);
        $this->assertNotNull($job->fresh()->next_retry_at);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $uploaded = '';
        Http::fake([
            '*/health' => Http::response(['connected' => true]),
            '*/photos' => function ($request) use (&$uploaded) {
                $uploaded = $request->body();

                return Http::response(['cloud_photo_id' => 95]);
            },
            '*/publish' => Http::response(['cloud_gallery_id' => 45, 'public_url' => 'https://cloud.example/select/secure-token']),
            '*' => Http::response(['cloud_gallery_id' => 45]),
        ]);
        $sync->process($job);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('synced', $photo->fresh()->sync_status);
        $this->assertStringContainsString(Storage::disk('photohub_local')->get($photo->preview_path), $uploaded);
        $this->assertStringNotContainsString(Storage::disk('photohub_local')->get($photo->original_path), $uploaded);
        $this->assertStringContainsString($photo->uuid, $uploaded);
    }

    public function test_cloud_removal_never_deletes_local_photos(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $photo = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('keep.jpg')])[0];
        Http::fake(['*' => Http::response(['removed' => true])]);
        $sync = app(GallerySyncService::class);
        $sync->process($sync->enqueue($gallery, 'remove'));
        $this->assertSame('deleted_cloud', $gallery->fresh()->cloud_status);
        Storage::disk('photohub_local')->assertExists($photo->original_path);
        $this->assertDatabaseHas('photos', ['id' => $photo->id, 'deleted_at' => null]);
    }

    public function test_selection_matches_uuid_and_repeated_sync_is_idempotent(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $gallery->forceFill(['sync_uuid' => (string) Str::uuid()])->save();
        $photo = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('selected.jpg')])[0];
        $data = ['gallery_uuid' => $gallery->sync_uuid, 'submitted_at' => now()->toIso8601String(), 'photo_uuids' => [$photo->uuid]];
        app(SelectionSyncService::class)->apply($gallery, $data);
        app(SelectionSyncService::class)->apply($gallery, $data);
        $this->assertDatabaseHas('photo_selections', ['photo_id' => $photo->id, 'customer_id' => $gallery->customer_id]);
        $this->assertSame(1, $gallery->fresh()->submitted_selection_count);
    }

    public function test_api_credentials_are_required_and_tenant_cannot_read_or_mutate_foreign_gallery(): void
    {
        config(['photohub.mode' => 'cloud', 'photohub.allow_http' => true]);
        $plain = Str::random(64);
        DB::table('studio_api_tokens')->insert(['business_id' => $this->business->id, 'token_hash' => hash('sha256', $plain)]);
        $foreign = Business::create(['name' => 'Other', 'slug' => 'other', 'email' => 'other@example.test', 'status' => 'active']);
        $gallery = $this->gallery();
        $gallery->forceFill(['business_id' => $foreign->id, 'sync_uuid' => (string) Str::uuid(), 'cloud_replica' => true])->save();
        $base = '/api/sync/v1/galleries/'.$gallery->sync_uuid;
        $this->getJson($base.'/selections')->assertUnauthorized();
        $this->withToken($plain)->getJson($base.'/selections')->assertNotFound();
        $this->withToken($plain)->postJson($base.'/publish', ['kind' => 'preview', 'uuids' => [Str::uuid()]])->assertNotFound();
        $this->withToken($plain)->deleteJson($base)->assertOk();
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id]);
    }

    public function test_cloud_publish_is_idempotent_and_preview_validation_and_quota_are_enforced(): void
    {
        config(['photohub.mode' => 'cloud', 'photohub.allow_http' => true]);
        $plain = Str::random(64);
        DB::table('studio_api_tokens')->insert(['business_id' => $this->business->id, 'token_hash' => hash('sha256', $plain)]);
        $uuid = (string) Str::uuid();
        $base = '/api/sync/v1/galleries/'.$uuid;
        $payload = ['name' => 'Cloud gallery', 'expires_at' => now()->addDays(30)->toIso8601String(), 'require_exact_selection' => false, 'downloads_enabled' => true];
        $this->withToken($plain)->putJson($base, $payload)->assertOk();
        $this->withToken($plain)->putJson($base, $payload)->assertOk();
        $this->assertSame(1, Gallery::where('sync_uuid', $uuid)->count());
        $photoUuid = (string) Str::uuid();
        $this->withToken($plain)->post($base.'/photos', ['uuid' => $photoUuid, 'kind' => 'preview', 'filename' => 'test.jpg', 'file' => UploadedFile::fake()->image('preview.jpg', 1600, 900)], ['Accept' => 'application/json'])->assertOk();
        $this->withToken($plain)->post($base.'/photos', ['uuid' => $photoUuid, 'kind' => 'preview', 'filename' => 'test.jpg', 'file' => UploadedFile::fake()->image('preview.jpg', 1600, 900)], ['Accept' => 'application/json'])->assertOk();
        $one = $this->withToken($plain)->postJson($base.'/publish', ['kind' => 'preview', 'uuids' => [$photoUuid]])->assertOk()->json('public_url');
        $two = $this->withToken($plain)->postJson($base.'/publish', ['kind' => 'preview', 'uuids' => [$photoUuid]])->assertOk()->json('public_url');
        $this->assertSame($one, $two);
        $this->assertGreaterThan(0, app(StorageQuotaService::class)->usage($this->business)['used']);
        $this->withToken($plain)->post($base.'/photos', ['uuid' => (string) Str::uuid(), 'kind' => 'preview', 'filename' => 'huge.jpg', 'file' => UploadedFile::fake()->image('huge.jpg', 2100, 100)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->withToken($plain)->deleteJson($base)->assertOk();
        $this->withToken($plain)->deleteJson($base)->assertOk();
    }

    public function test_final_download_returns_full_image_and_optional_zip_and_blocks_expiry(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $photo = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('final.jpg', 2400, 1600)], true, true)[0];
        $gallery->update(['status' => 'final_published']);
        $token = app(GalleryAccessService::class)->generate($gallery, 'final_delivery')->plainToken();
        $response = $this->get(route('delivery.download', [$token, $photo]))->assertOk()->assertDownload('final.jpg')->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(Storage::disk('photohub_local')->get($photo->original_path), $response->streamedContent());
        $this->get(route('delivery.show', $token))->assertOk()->assertSee('Download Photo')->assertSee('Download All as ZIP');
        $this->post(route('delivery.zip', $token), ['all' => 1])->assertOk()->assertDownload();
        $this->get(route('delivery.download', [Str::random(48), $photo]))->assertForbidden();
        $gallery->update(['expires_at' => now()->subDay()]);
        $this->get(route('delivery.download', [$token, $photo]))->assertForbidden();
        Storage::disk('photohub_local')->assertExists($photo->original_path);
    }

    public function test_buttons_only_queue_work_and_removal_requires_confirmation(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $this->actingAs(User::where('email', 'owner@example.com')->first())->withSession(['business_id' => $this->business->id]);
        $this->post(route('sync.action', $gallery), ['action' => 'publish'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sync_jobs', ['gallery_id' => $gallery->id, 'type' => 'publish', 'status' => 'queued']);
        $this->post(route('sync.action', $gallery), ['action' => 'remove'])->assertSessionHasErrors('confirm_remove');
        $this->post(route('sync.action', $gallery), ['action' => 'remove', 'confirm_remove' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sync_jobs', ['gallery_id' => $gallery->id, 'type' => 'publish', 'status' => 'cancelled']);
        $this->get(route('sync.index'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_final_sync_sends_full_resolution_final_and_no_unrelated_originals(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $proof = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('proof.jpg', 2100, 1000)])[0];
        $final = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('final.jpg', 2500, 1600)], true, true)[0];
        $uploaded = '';
        Http::fake([
            '*/photos' => function ($request) use (&$uploaded) {
                $uploaded .= $request->body();

                return Http::response(['cloud_photo_id' => 4]);
            },
            '*/publish' => Http::response(['public_url' => 'https://cloud.example/delivery/final-token']),
            '*' => Http::response(['cloud_gallery_id' => 6]),
        ]);
        $sync = app(GallerySyncService::class);
        $job = $sync->enqueue($gallery, 'finals');
        $sync->process($job);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertStringContainsString(Storage::disk('photohub_local')->get($final->original_path), $uploaded);
        $this->assertStringNotContainsString($proof->uuid, $uploaded);
        $this->assertSame('local_only', $proof->fresh()->sync_status);
    }

    public function test_exif_orientation_is_preserved_without_modifying_original(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('EXIF extension required.');
        }
        $this->local();
        $file = UploadedFile::fake()->image('portrait.jpg', 120, 80);
        $bytes = file_get_contents($file->getRealPath());
        // Minimal little-endian EXIF IFD with orientation 6 (90 degrees clockwise).
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x112, 3, 1, 6)."\0\0".pack('V', 0);
        $bytes = substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
        file_put_contents($file->getRealPath(), $bytes);
        $photo = app(PhotoUploadService::class)->storeBatch($this->gallery(), [$file])[0];
        $size = getimagesize(Storage::disk('photohub_local')->path($photo->preview_path));
        $this->assertSame([80, 120], [$size[0], $size[1]]);
        $this->assertSame($bytes, Storage::disk('photohub_local')->get($photo->original_path));
    }

    public function test_selection_conflict_does_not_overwrite_local_submission(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $gallery->forceFill(['sync_uuid' => (string) Str::uuid()])->save();
        [$a, $b] = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]);
        $service = app(SelectionSyncService::class);
        $service->apply($gallery, ['gallery_uuid' => $gallery->sync_uuid, 'submitted_at' => now()->toIso8601String(), 'photo_uuids' => [$a->uuid]]);
        try {
            $service->apply($gallery, ['gallery_uuid' => $gallery->sync_uuid, 'submitted_at' => now()->toIso8601String(), 'photo_uuids' => [$b->uuid]]);
            $this->fail('Conflicting selection was accepted.');
        } catch (SelectionSyncConflict $e) {
            $this->assertDatabaseHas('photo_selections', ['photo_id' => $a->id]);
            $this->assertDatabaseMissing('photo_selections', ['photo_id' => $b->id]);
        }
    }

    public function test_batches_resume_without_reuploading_synced_photos(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $files = [];
        for ($i = 0; $i < 21; $i++) {
            $files[] = UploadedFile::fake()->image($i.'.jpg', 10, 10);
        }
        app(PhotoUploadService::class)->storeBatch($gallery, $files);
        $uploads = 0;
        Http::fake([
            '*/photos' => function () use (&$uploads) {
                return Http::response(['cloud_photo_id' => ++$uploads]);
            },
            '*/publish' => Http::response(['public_url' => 'https://cloud.example/select/batched']),
            '*' => Http::response(['cloud_gallery_id' => 6]),
        ]);
        $sync = app(GallerySyncService::class);
        $job = $sync->enqueue($gallery, 'publish');
        $sync->process($job);
        $this->assertSame('queued', $job->fresh()->status);
        $this->assertSame(20, $uploads);
        $sync->process($job);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(21, $uploads);
    }
}
