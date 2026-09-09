# PhotoHub local-first / cloud deployment

This is one Laravel 12 + Blade codebase. Existing cloud behavior remains the default. No existing photos or customer records are moved by the migration. Local mode does not contact the cloud when rendering ordinary pages.

## Configuration

Keep the current database and storage backed up before changing an installation's configuration. The workspace currently uses SQLite; use Laravel's existing MySQL connection settings for a Windows/MySQL installation. Changing DB_CONNECTION does **not** transfer existing records to another database.

Example local `.env`:

```dotenv
APP_URL=http://localhost/OOP/pichaFlow/public
PHOTOHUB_MODE=local
PHOTOHUB_LOCAL_STORAGE_PATH=D:/PhotoHubStorage
PHOTOHUB_LOCAL_BUSINESS_ID=1
PHOTOHUB_CLOUD_ENABLED=true
PHOTOHUB_CLOUD_URL=https://photos.example.com
PHOTOHUB_STUDIO_TOKEN=YOUR_UNIQUE_STUDIO_CREDENTIAL
PHOTOHUB_ALLOW_HTTP=false
PHOTOHUB_AUTO_SELECTIONS=true

# For a NEW MySQL installation, configure its own database:
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=photohub
DB_USERNAME=photohub
DB_PASSWORD=YOUR_LOCAL_DATABASE_PASSWORD
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

- `PHOTOHUB_MODE`: `cloud` preserves the SaaS installation. `local` and `hybrid` both keep photos locally and enable the outgoing sync UI. `hybrid` is an explicit local installation with optional cloud publishing; it is not an API receiver.
- `PHOTOHUB_LOCAL_STORAGE_PATH`: private filesystem root. Blank defaults to `storage/app/photohub`. The Apache/PHP Windows account needs write access. Keep this outside the public web directory.
- `PHOTOHUB_LOCAL_BUSINESS_ID`: **local database** business ID permitted to use this installation's outgoing credential. This need not equal the cloud business ID. Other local businesses cannot use that credential.
- `PHOTOHUB_CLOUD_ENABLED`: outgoing network access switch. Missing cloud settings do not prevent local uploads, business operations, or application boot.
- `PHOTOHUB_CLOUD_URL`: HTTPS origin/base path of the cloud installation, without `/api/sync/v1`.
- `PHOTOHUB_STUDIO_TOKEN`: unique token issued for the corresponding business on the cloud server; never a shared FTP/server password.
- `PHOTOHUB_ALLOW_HTTP`: development-only override for HTTP on BOTH installations. Keep false on deployed cloud systems.
- `PHOTOHUB_AUTO_SELECTIONS`: queues selection checks every five minutes for published, unsubmitted galleries.

After environment changes run `php artisan config:clear`. Install Composer/npm dependencies while online and retain the built assets for offline operation. Normal pages use bundled Bootstrap CSS, JavaScript and icon fonts; no runtime CDN is required. Password-reset email and explicitly external integrations still need their configured services.

## Cloud setup and credentials

Use `PHOTOHUB_MODE=cloud`, HTTPS, `APP_DEBUG=false`, and a correct public `APP_URL`. Provision the studio using the existing admin interface, including its subscription and cloud storage allocation. Deploy this code and run `php artisan migrate --force`.

On the **cloud host**, run:

```console
php artisan photohub:studio-token CLOUD_BUSINESS_ID
```

Copy the one-time credential into that studio's local environment. Only a SHA-256 token hash is stored on the cloud. To revoke all credentials for a studio:

```console
php artisan photohub:studio-token CLOUD_BUSINESS_ID --revoke
```

Issue a fresh token afterward if rotating credentials. Keep `.env` files out of version control and customer access. There is no universal credential or client access to the cloud database. Studio access is derived from the bearer token, never a caller-supplied business ID. The API is disabled in local/hybrid mode and rate limited in cloud mode.

## Storage and large imports

New local files use the `photohub_local` disk:

```text
PhotoHubStorage/studios/{business_id}/galleries/{gallery_id}/
  originals/{uuid}.jpg
  preview/{uuid}.jpg
  thumbnail/{uuid}.jpg
  edited/originals/{uuid}.jpg
  edited/preview/{uuid}.jpg
  edited/thumbnail/{uuid}.jpg
```

The existing singular preview/thumbnail conventions are retained. Legacy `businesses/...` and older stored paths remain on the original private `local` disk. `PhotoStorage` resolves the persisted prefix, so selecting a new local storage root never silently relocates old originals. When changing an already-populated local root, copy its contents and verify them before changing the configuration; do not discard the old root until verified.

Proof and final import forms submit small sequential batches (at most five files and normally 16 MiB per request). PHP must still accept the largest individual file. Suggested Windows PHP settings for final delivery are `upload_max_filesize=50M`, `post_max_size=64M`, `memory_limit=512M`, and `max_execution_time=180`. Original imports retain the existing 20 MiB application limit; final uploads allow 50 MiB. Restart Apache after changing php.ini. GD and EXIF are used for orientation-preserving, 1600px previews and 420px thumbnails; originals are never rewritten. JPEG quality remains 86. Preview size varies by content; the cloud rejects selection previews over 2 MiB or 2000px.

If an import pauses, the page retains the acknowledged batch offset so retry continues from that point. After an ambiguous connection loss, check the gallery first: a request may have completed on the server without its response reaching the browser. This import transport does not deduplicate such ambiguous uploads. Cloud uploads **are** idempotent by persistent UUID.

## Publishing and retries

1. Create the customer/gallery and import photos locally.
2. Click **Publish Online**. The web request only adds a durable `sync_jobs` record; it does not wait for the internet.
3. The worker checks the authenticated cloud API, creates/updates gallery metadata, then sends up to 20 optimized previews per batch. Original proof files never enter the upload request.
4. Once the manifest is acknowledged, the existing secure selection token/link appears on the gallery and dashboard. Public links use random tokens, not gallery IDs.
5. The customer selects/submits photos using the existing PIN-protected selection UI.
6. **Sync Customer Selection** imports the submitted UUID set transactionally. Unknown UUIDs or a differing locked local submission are conflicts, not silent overwrites. Existing selection rules and extra-photo invoicing run locally. To accept a conflicting cloud submission, review and reopen the local selection before retrying. Reopening locally does not reopen the customer's cloud submission.
7. Upload edits through **Finished photos**, then **Publish Final Photos**. Only the active final files are uploaded at full resolution. Legacy galleries containing final files in `photos` are supported when no `final_photos` exist. The separate final delivery link uses the existing final gallery.

Run the durable processor manually:

```console
php artisan photohub:sync --limit=10
```

For continuous operation, run `php artisan schedule:work` in a managed background process, or configure Windows Task Scheduler to run `php artisan schedule:run` every minute with this project as its working directory. The scheduler processes pending batches and retries failures with capped exponential backoff. It requires the computer to be awake. No cloud process runs inside normal page requests, even when Laravel's default queue is `sync`.

`ProcessGallerySync` also supports Laravel workers when explicitly dispatched. For that alternative, set the worker timeout to at least 1200 seconds and its queue connection `retry_after` above 1200. The built-in scheduled command does not require dispatching this job or a separate queue worker. Database/cache gallery locks prevent simultaneous processing; abandoned running operations become retryable after 30 minutes.

The dashboard shows pending/failed **operations**, local/cloud photo counts, gallery state, last synchronization, links, and explicit connection checks. Cloud usage is a cached response from the cloud server, marked as the last check. Local original/final bytes are shown separately, with previews additional. Existing cloud quotas and admin allocation controls are enforced on the receiver, including retained final versions. Local imports do not consume or enforce cloud quotas/subscription storage limits.

**Remove From Cloud** requires confirmation and queues removal. It cancels earlier waiting/failed operations, revokes cloud links before file cleanup, and only deletes the matching cloud replica. Interrupted deletion can be retried. Local originals, edits, galleries and customer selections remain. Republishing after removal creates a new cloud gallery/link. Never delete a local gallery merely to free cloud quota.

The existing 30-day expiry remains in force. Expiry disables customer access/downloads; the existing lifecycle service retains files. This upgrade adds no automatic deletion of expired originals, locally or in the cloud. Retained cloud files continue to consume quota until explicit cloud removal.

## Direct customer downloads

Each final card and its enlarged viewer has **Download Photo**. The authorized delivery controller returns the actual full-resolution JPEG/PNG/WebP using Laravel filesystem download responses, correct content type, attachment headers and private/no-store caching. Existing gallery ownership, token validity, expiry, downloads-enabled checks and legacy paid-gallery authorization remain enforced. Filesystem paths are never included in public download URLs.

**Download selected as ZIP** and **Download All as ZIP** remain explicit optional actions. No attempt is made to force dozens of browser downloads or bypass mobile browser permissions. Browsing continues to use optimized previews.

## Migration and changed files

One new additive migration: `database/migrations/2026_09_09_000100_add_local_cloud_sync.php`. It adds gallery sync identity/state, photo/final-photo cloud state, `studio_api_tokens`, and durable `sync_jobs`. The existing photo UUID columns, tenant tables, selection tables and secure access-token system are reused. Existing migrations were not rewritten.

New implementation files:

- `config/photohub.php`
- `app/Models/SyncJob.php`
- `app/Services/PhotoStorage.php`, `CloudApiService.php`, `GallerySyncService.php`, `SelectionSyncService.php`
- `app/Exceptions/SelectionSyncConflict.php`
- `app/Jobs/ProcessGallerySync.php`
- `app/Http/Middleware/AuthenticateStudioSync.php`
- `app/Http/Controllers/CloudSyncApiController.php`, `SyncController.php`
- `routes/api.php`
- `resources/views/components/gallery-sync.blade.php`, `resources/views/sync/index.blade.php`
- `public/js/photo-upload.js`, `public/vendor/bootstrap/*`, `public/vendor/bootstrap-icons/*`
- `tests/Feature/LocalCloudSyncTest.php`
- This document and the migration above.

Existing files extended:

- `README.md`, `.env.example`, `config/filesystems.php`, `bootstrap/app.php`, `routes/web.php`, `routes/console.php`
- `app/Services/PhotoUploadService.php`, `StorageQuotaService.php`, `SubscriptionLimitService.php`, `PhotoSelectionService.php`, `ZipDownloadService.php`
- `app/Http/Controllers/GalleryController.php`, `GalleryWorkflowController.php`, `GuestSelectionController.php`, `FinalDeliveryController.php`, `PublicGalleryController.php`, `PublicBusinessController.php`
- `resources/views/galleries/show.blade.php`, `workflow.blade.php`
- `resources/views/layouts/app.blade.php`, `portal/index.blade.php`, `components/storage-usage.blade.php`
- `resources/views/public/business.blade.php`, `delivery.blade.php`, `gallery.blade.php`, `selection.blade.php`, `selection-pin.blade.php`, `link-unavailable.blade.php`

The workspace already contained substantial uncommitted gallery/quota/UI work; those changes were preserved and reused.

## Verification and deployment limits

Verified in this workspace: **68 tests passed (361 assertions)**; production Vite build, Blade compilation, PHP/JavaScript syntax checks and sync route registration passed.

Run `php artisan test --compact`, `php artisan view:cache`, `php artisan route:list --path=api/sync`, and `npm run build`. Tests cover offline pages/uploads, cloud-only quota behavior, tenant authentication, idempotent publishing, preview-only upload bytes, final upload bytes, UUID matching/conflicts, retry batches, confirmed cloud removal, EXIF preservation, direct image downloads, expiry, unauthorized tokens and optional ZIP downloads, plus the existing regression suite.

The workspace SQLite database was backed up under private `storage/app/private/backups/` before applying the migration. Existing data across 53 tables was compared to that backup and remained unchanged. The current machine's MySQL server was unreachable; MySQL execution and a real two-installation HTTPS sync remain deployment checks. No cloud URL/token was supplied, and no external gallery was published. Configure the two installations and run a small real gallery through selection and final delivery before a large import.
