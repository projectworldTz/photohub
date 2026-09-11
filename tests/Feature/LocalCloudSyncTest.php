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
        $this->actingAs(User::where('email', 'owner@example.com')->first())->withSession(['business_id' => $this->business->id])->get(route('galleries.show', $gallery))->assertOk()->assertSee('Create Customer Link');
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
        $this->assertNull($job->fresh()->next_retry_at);
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

    public function test_rejected_upload_preserves_connection_health_and_reports_safe_error(): void
    {
        $this->local();
        $gallery = $this->gallery();
        app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('photo.jpg')]);
        Http::fake([
            '*/health' => Http::response(['connected' => true]),
            '*/photos' => Http::response(['message' => 'private server details'], 403),
            '*' => Http::response(['cloud_gallery_id' => 45]),
        ]);
        $sync = app(GallerySyncService::class);
        $job = $sync->enqueue($gallery, 'publish');
        $sync->process($job);
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertStringContainsString('(403)', $job->fresh()->last_error);
        $this->assertStringNotContainsString('private server details', $job->fresh()->last_error);
        $this->assertSame('Connected (last sharing check)', \Illuminate\Support\Facades\Cache::get('photohub-connection-'.$gallery->business_id));
    }

    public function test_cloud_removal_never_deletes_local_photos(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $photo = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('keep.jpg')])[0];
        Http::fake(['*/health' => Http::response(['connected' => true]), '*' => Http::response(['removed' => true])]);
        $sync = app(GallerySyncService::class);
        $sync->process($sync->enqueue($gallery, 'remove'));
        $this->assertSame('removed_online', $gallery->fresh()->preview_share_status);
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
        $this->get(route('delivery.show', $token))->assertOk()->assertSee('Download selected')->assertSee('Download all photos');
        $this->post(route('delivery.zip', $token), ['all' => 1])->assertOk()->assertDownload();
        $this->get(route('delivery.download', [Str::random(48), $photo]))->assertForbidden();
        $gallery->update(['expires_at' => now()->subDay()]);
        $this->get(route('delivery.download', [$token, $photo]))->assertForbidden();
        Storage::disk('photohub_local')->assertExists($photo->original_path);
    }

    public function test_explicit_actions_process_without_worker_and_removal_requires_confirmation(): void
    {
        $this->local();
        $gallery = $this->gallery();
        app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('share.jpg')]);
        Http::fake([
            '*/health' => Http::response(['connected' => true]),
            '*/photos' => Http::response(['cloud_photo_id' => 4]),
            '*/publish' => Http::response(['public_url' => 'https://cloud.example/select/ready']),
            '*' => Http::response(['cloud_gallery_id' => 6]),
        ]);
        $this->actingAs(User::where('email', 'owner@example.com')->first())->withSession(['business_id' => $this->business->id]);
        $this->postJson(route('online.start', [$gallery, 'previews']))->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('url', 'https://cloud.example/select/ready');
        $this->deleteJson(route('online.remove', $gallery))->assertUnprocessable();
        $this->deleteJson(route('online.remove', $gallery), ['confirm_remove' => 1])->assertOk()->assertJsonPath('status', 'completed');
        $this->get(route('online.index'))->assertOk()->assertSee('Online Sharing');
    }

    public function test_final_sync_sends_full_resolution_final_and_no_unrelated_originals(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $proof = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('proof.jpg', 2100, 1000)])[0];
        $gallery->update(['downloads_enabled' => false]);
        $final = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('final.jpg', 2500, 1600)], true, true)[0];
        $uploaded = '';
        Http::fake([
            '*/health' => Http::response(['connected' => true]),
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
        $this->assertTrue($gallery->fresh()->downloads_enabled);
        $this->assertSame('https://cloud.example/delivery/final-token', $gallery->fresh()->cloud_final_url);
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
            '*/health' => Http::response(['connected' => true]),
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
        $this->assertSame(1, $uploads);
        for ($i = 0; $i < 20; $i++) { $sync->process($job); }
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(21, $uploads);
    }
    public function test_offline_work_does_not_schedule_cloud_operations(): void
    {
        $this->local();
        config(['photohub.cloud_enabled' => false]);
        $count = \App\Models\SyncJob::count();
        $this->actingAs(User::where('email', 'owner@example.com')->first())->withSession(['business_id' => $this->business->id]);
        $this->post(route('galleries.store'), ['customer_id' => Customer::where('business_id', $this->business->id)->firstOrFail()->id, 'name' => 'Offline new gallery', 'type' => 'proof', 'privacy' => 'private', 'status' => 'draft'])->assertSessionHasNoErrors();
        $gallery = Gallery::where('name', 'Offline new gallery')->firstOrFail();
        app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('offline.jpg')]);
        foreach (['dashboard', 'customers.index', 'bookings.index', 'shoots.index', 'galleries.index', 'invoices.index', 'reports.index', 'staff.index', 'equipment.index', 'portfolio.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('galleries.show', $gallery))->assertOk();
        $this->get(route('galleries.workflow', $gallery))->assertOk()->assertSee('Save finished photos locally')->assertDontSee('Create download link');
        $this->assertSame($count, \App\Models\SyncJob::count());
        Http::assertNothingSent();
    }

    public function test_failed_final_file_does_not_block_other_files_previews_or_selections(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $gallery->forceFill(['cloud_gallery_id' => 5, 'cloud_url' => 'https://cloud.example/select/existing', 'preview_share_status' => 'online'])->save();
        $proof = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('proof.jpg')])[0];
        [$bad, $good] = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('bad.jpg'), UploadedFile::fake()->image('good.jpg')], true, true);
        $sync = app(GallerySyncService::class);
        $finalJob = $sync->enqueue($gallery, 'finals');
        Http::fake([
            '*/health' => Http::response(['connected' => true]),
            '*/photos' => fn ($request) => str_contains($request->body(), $bad->uuid) ? Http::response([], 403) : Http::response(['cloud_photo_id' => 5]),
            '*/selections' => Http::response(['gallery_uuid' => $gallery->fresh()->sync_uuid, 'submitted_at' => null, 'photo_uuids' => []]),
            '*/publish' => Http::response(['public_url' => 'https://cloud.example/select/existing']),
            '*' => Http::response(['cloud_gallery_id' => 5]),
        ]);
        $sync->process($finalJob);
        $this->assertSame('failed', $bad->fresh()->sync_status);
        $this->assertSame('queued', $finalJob->fresh()->status);
        $sync->process($finalJob);
        $this->assertSame('synced', $good->fresh()->sync_status);
        $this->assertSame('failed', $finalJob->fresh()->status);
        $this->assertSame('proofs_ready', $gallery->fresh()->status);
        $this->assertSame('online', $gallery->fresh()->preview_share_status);
        $previewJob = $sync->enqueue($gallery->fresh(), 'publish');
        $sync->process($previewJob);
        $this->assertSame('completed', $previewJob->fresh()->status);
        $selectionJob = $sync->enqueue($gallery->fresh(), 'selections');
        $sync->process($selectionJob);
        $this->assertSame('completed', $selectionJob->fresh()->status);
        $this->assertNotNull($gallery->fresh()->selection_synced_at);
        Storage::disk('photohub_local')->assertExists($proof->original_path);
        Http::swap(new Factory);
        $sent = [];
        Http::fake([
            '*/health' => Http::response(['connected' => true]),
            '*/photos' => function ($request) use (&$sent) { $sent[] = $request->body(); return Http::response(['cloud_photo_id' => 8]); },
            '*/publish' => Http::response(['public_url' => 'https://cloud.example/delivery/ready']),
            '*' => Http::response(['cloud_gallery_id' => 5]),
        ]);
        $sync->process($sync->enqueue($gallery->fresh(), 'finals'));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString($bad->uuid, $sent[0]);
        $this->assertStringNotContainsString($good->uuid, $sent[0]);
        $this->assertSame('https://cloud.example/delivery/ready', $gallery->fresh()->cloud_final_url);
    }

    public function test_preview_failure_preserves_existing_delivery_and_local_files(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $gallery->forceFill(['cloud_final_url' => 'https://cloud.example/delivery/existing', 'final_share_status' => 'online'])->save();
        $photo = app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('keep.jpg')])[0];
        Http::fake(['*' => Http::response([], 503)]);
        $service = app(GallerySyncService::class);
        $service->process($service->enqueue($gallery, 'publish'));
        $this->assertSame('online', $gallery->fresh()->final_share_status);
        $this->assertSame('https://cloud.example/delivery/existing', $gallery->fresh()->cloud_final_url);
        Storage::disk('photohub_local')->assertExists($photo->original_path);
    }

    public function test_legacy_jobs_and_schedules_cannot_start_unrequested_transfers(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $job = \App\Models\SyncJob::create(['business_id' => $gallery->business_id, 'gallery_id' => $gallery->id, 'type' => 'finals', 'status' => 'queued', 'queued_at' => now()]);
        app(GallerySyncService::class)->process($job);
        (new \App\Jobs\ProcessGallerySync($job->id))->handle(app(GallerySyncService::class));
        $this->artisan('photohub:sync')->assertSuccessful();
        $this->assertSame(0, $job->fresh()->attempts);
        $this->assertNull($job->fresh()->explicit_requested_at);
        Http::assertNothingSent();
    }

    public function test_continuation_requires_matching_business_gallery_and_explicit_operation(): void
    {
        $this->local();
        $one = $this->gallery();
        $two = $this->gallery();
        $job = app(GallerySyncService::class)->enqueue($one, 'publish');
        $this->actingAs(User::where('email', 'owner@example.com')->first())->withSession(['business_id' => $this->business->id]);
        $this->postJson(route('online.continue', [$two, $job]))->assertForbidden();
        $job->update(['explicit_requested_at' => null]);
        $this->postJson(route('online.continue', [$one, $job]))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_revoked_tokens_and_non_https_requests_are_rejected(): void
    {
        config(['photohub.mode' => 'cloud', 'photohub.allow_http' => true]);
        $token = Str::random(64);
        DB::table('studio_api_tokens')->insert(['business_id' => $this->business->id, 'token_hash' => hash('sha256', $token), 'revoked_at' => now()]);
        $this->withToken($token)->getJson('/api/sync/v1/health')->assertUnauthorized();
        DB::table('studio_api_tokens')->where('business_id', $this->business->id)->update(['revoked_at' => null]);
        config(['photohub.allow_http' => false]);
        $this->withToken($token)->getJson('/api/sync/v1/health')->assertForbidden();
    }

    public function test_pruning_only_removes_old_completed_explicit_operation_records(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $legacy = \App\Models\SyncJob::create(['business_id' => $gallery->business_id, 'gallery_id' => $gallery->id, 'type' => 'publish', 'status' => 'completed', 'queued_at' => now()->subDays(100), 'completed_at' => now()->subDays(100)]);
        $old = app(GallerySyncService::class)->enqueue($gallery, 'publish');
        $old->update(['status' => 'completed', 'completed_at' => now()->subDays(100)]);
        $failed = app(GallerySyncService::class)->enqueue($gallery, 'finals');
        $failed->update(['status' => 'failed']);
        $this->artisan('photohub:sharing-prune')->assertSuccessful();
        $this->assertNotNull($legacy->fresh());
        $this->assertNull($old->fresh());
        $this->assertNotNull($failed->fresh());
        $this->assertNotNull($gallery->fresh());
    }

    public function test_default_three_gb_cloud_quota_is_enforced(): void
    {
        config(['photohub.mode' => 'cloud']);
        $business = Business::create(['name' => 'Quota', 'email' => 'quota@example.test', 'slug' => 'quota', 'status' => 'active']);
        $this->assertSame(3 * StorageQuotaService::GB, $business->fresh()->storage_limit_bytes);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(StorageQuotaService::class)->assertFits($business, 3 * StorageQuotaService::GB + 1);
    }

    public function test_additive_migration_retains_existing_links_and_legacy_history(): void
    {
        $this->local();
        $gallery = $this->gallery();
        $gallery->forceFill(['sync_uuid' => (string) Str::uuid(), 'cloud_gallery_id' => 3,
            'cloud_url' => 'https://cloud.example/select/old', 'cloud_final_url' => 'https://cloud.example/delivery/old'])->save();
        $job = \App\Models\SyncJob::create(['business_id' => $gallery->business_id, 'gallery_id' => $gallery->id, 'type' => 'finals', 'status' => 'failed', 'queued_at' => now()]);
        $before = $gallery->only(['id', 'sync_uuid', 'cloud_gallery_id', 'cloud_url', 'cloud_final_url']);
        $migration = require database_path('migrations/2026_09_11_000100_add_explicit_online_sharing.php');
        $migration->up();
        $this->assertSame($before, $gallery->fresh()->only(array_keys($before)));
        $this->assertSame('online', $gallery->fresh()->preview_share_status);
        $this->assertSame('online', $gallery->fresh()->final_share_status);
        $this->assertNotNull($job->fresh());
        $this->assertNull($job->fresh()->explicit_requested_at);
    }

    public function test_browser_continuation_finishes_multiple_photos_without_a_worker(): void
    {
        $this->local();
        $gallery = $this->gallery();
        app(PhotoUploadService::class)->storeBatch($gallery, [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')]);
        Http::fake([
            '*/health' => Http::response(['connected' => true]),
            '*/photos' => Http::response(['cloud_photo_id' => 9]),
            '*/publish' => Http::response(['public_url' => 'https://cloud.example/select/browser']),
            '*' => Http::response(['cloud_gallery_id' => 5]),
        ]);
        $this->actingAs(User::where('email', 'owner@example.com')->first())->withSession(['business_id' => $this->business->id]);
        $first = $this->postJson(route('online.start', [$gallery, 'previews']))->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('uploaded', 1);
        $this->postJson($first->json('continue_url'))->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('uploaded', 2)->assertJsonPath('url', 'https://cloud.example/select/browser');
    }

}
