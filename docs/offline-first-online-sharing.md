# Offline-first PhotoHub with explicit online sharing

## What changed

Local gallery creation, edits, uploads and business modules do not contact the cloud or create sharing jobs. The same codebase still supports `PHOTOHUB_MODE=cloud`; legacy `hybrid` remains a local-mode alias.

Online Sharing has separate Preview Gallery and Final Delivery sections. Each retains its own URL, latest attempt state, error and successful publication timestamp. A failed update leaves any existing link visible and does not change the local gallery's workflow status or the other collection's sharing status.

The workflow is:

1. Add original photos locally. Click **Share Previews Online** (or **Update Online Gallery**) when ready. Only optimized previews are sent.
2. Copy the selection link and send it to the client yourself.
3. Click **Get Client Selections** to import submitted UUID selections. Conflicting local submissions require review/reopening; they are not overwritten silently. The last-check timestamp is recorded even when the client has not submitted yet.
4. Edit locally. Use **Save finished photos locally** on the gallery workflow page.
5. Click **Upload Finished Photos** in Online Sharing. This publishes the finished collection with downloads enabled, like the existing cloud delivery-publish action. Copy the download link when complete.

No local preview/download link generation step is needed for online sharing. Cloud installations retain their existing direct publication screens.

## Processing and recovery

- The first authenticated POST processes one file. The browser makes further authenticated POSTs for that specific operation while the page stays open. No queue worker or scheduler is needed for sharing or ordinary local use.
- Closing the page stops further batches after the current request. Click the same action to resume; successful files are skipped by persistent UUID/upload state. Without JavaScript, the page offers **Continue sharing upload** between batches.
- One failed file is recorded separately and subsequent files are attempted. Publication occurs only when all ready files in that collection are uploaded. A later explicit retry attempts only unfinished files.
- There is a short per-gallery lock for concurrent requests, but no dependency on older failed jobs. Preview updates, selection retrieval and final delivery can proceed independently after another action fails.
- Removing an online gallery cancels paused explicit operations under that lock, revokes/removes cloud data through the existing endpoint, and retains local originals/finals. Legacy history remains untouched.
- Completed/cancelled explicit operation records older than 90 days are pruned on new sharing actions and by the optional daily maintenance command. Legacy records and failed operations are retained.
- `photohub:sync` is a compatibility no-op. Old `ProcessGallerySync` queue messages are no-ops. Automatic selection polling and the every-minute sync schedule have been removed.

## Files

- `app/Services/OnlineGalleryService.php`: bounded uploads, independent statuses, safe errors, locking, retry cursor and pruning.
- `app/Services/GallerySyncService.php`: compatibility subclass; old callers use the new implementation.
- `app/Services/CloudApiService.php`: retained authenticated cloud client with clearer configuration errors.
- `app/Http/Controllers/SyncController.php`: explicit action and continuation endpoints, legacy route support, result/progress responses.
- `app/Jobs/ProcessGallerySync.php`, `routes/console.php`: remove automatic transfer execution; add `photohub:sharing-prune`.
- `app/Models/Gallery.php`, `app/Models/SyncJob.php`: sharing timestamp casts.
- `config/photohub.php`, `.env.example`: disable automatic selection polling.
- `routes/web.php`: new explicit routes alongside the old aliases.
- `resources/views/components/gallery-sync.blade.php`, `resources/views/sync/index.blade.php`: Online Sharing UI with separate collections, copied links, failure details and manual selections.
- `resources/views/galleries/workflow.blade.php`: separate local finished-photo storage from online delivery.
- `resources/views/layouts/app.blade.php`, `resources/views/components/storage-usage.blade.php`: navigation and terminology.
- `public/js/online-sharing.js`: authenticated, sequential browser batches and copy-link handling.
- `tests/Feature/LocalCloudSyncTest.php`, `tests/TestCase.php`: offline, explicit sharing, security, failure/resume and migration coverage; tests use an explicit cloud default rather than inheriting the developer's local mode.

Earlier UI refinements in this working tree are retained.

## Database and compatibility

Additive migration: `2026_09_11_000100_add_explicit_online_sharing.php`.

Adds `preview_share_status`, `final_share_status`, their error/timestamp fields, `selection_share_error`, plus `explicit_requested_at` and `last_photo_id` on the existing operation table. Existing nonempty URLs backfill to online. No gallery, photo, customer, financial or historical job rows are removed. Migration rollback deliberately retains the new metadata; reapplying the migration is safe.

`cloud_gallery_id`, `sync_uuid`, `cloud_url`, `cloud_final_url` and file UUIDs stay intact. Legacy `cloud_status`, `sync_error` and `last_synced_at` remain for compatibility/history but no longer represent local health. The existing per-file `sync_status` and cloud file IDs are reused internally; `synced` means that file has uploaded successfully.

Before the local migration, a SQLite backup was created in `storage/app/private/photohub-before-sharing-*.sqlite`. Before/after counts and a hash of cloud IDs/UUIDs/URLs confirmed preservation. Never expose that backup through the web server.

## Routes and API

New local routes:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/online-sharing` | Sharing overview |
| POST | `/online-sharing/connection` | Explicit health check |
| POST | `/galleries/{gallery}/online/previews` | Share/update previews |
| POST | `/galleries/{gallery}/online/selections` | Retrieve selections |
| POST | `/galleries/{gallery}/online/finals` | Share/update final delivery |
| POST | `/galleries/{gallery}/online/operations/{operation}` | Continue an explicitly requested batch |
| DELETE | `/galleries/{gallery}/online` | Confirmed online removal |

Existing `/sync`, `/sync/connection` and `/galleries/{gallery}/sync` remain compatible. The old generic retry button instructs the user to choose a specific action instead of replaying unrelated work.

Existing gallery API remains `/api/sync/v1/`. New studios require the cloud registration endpoint deployed below. Persistent customer tokens and existing links are reused by the existing publish endpoint.

## Security and storage

HTTPS by default, bearer-token authentication, hashed cloud credentials, active-studio checks, permission and tenant checks, UUID/image validation, expiry and 3 GB default quota are retained. Continuation endpoints verify the operation belongs to the authenticated business and gallery and was explicitly requested. Errors do not include HTTP response bodies, secrets or filesystem paths. Returned customer URLs must match the configured cloud origin. Preview sharing cannot send the original file path. Removal never deletes local files.

Expiration blocks cloud access without automatically deleting local files. Existing cloud removal and quota services retain their safe cleanup behavior. No production gallery was deleted to test cleanup.

## Local Windows deployment

1. Back up the database and photo storage. Stop any old scheduler/queue processes during deployment so they cannot run old in-memory code.
2. Deploy this working tree, including the new service, JavaScript and migration.
3. Use these local settings (retain your existing business ID and secret token):

   ```dotenv
   PHOTOHUB_MODE=local
   PHOTOHUB_CLOUD_ENABLED=true
   PHOTOHUB_CLOUD_URL=https://photohub.projectworldtz.com
   PHOTOHUB_LOCAL_BUSINESS_ID=<existing studio ID>
   PHOTOHUB_STUDIO_TOKEN=<existing secret token>
   PHOTOHUB_ALLOW_HTTP=false
   PHOTOHUB_AUTO_SELECTIONS=false
   ```

4. Run `php artisan migrate --force` and `php artisan optimize:clear`. The new migration and cache clearing have already been run in this local workspace.
5. Start the app normally, for example `php artisan serve`. Do not start `photohub:sync` or `schedule:work` just for online sharing. Other optional scheduled business reminders can still use Laravel's scheduler.
6. Open a gallery, choose a sharing action, and keep that page open while it uploads. With `PHOTOHUB_CLOUD_ENABLED=false` or no internet, normal local modules continue working.

## Truehost / cPanel deployment

1. Back up the cloud database and storage. Deploy the same code, preserving `.env`, `APP_KEY`, token records and storage. Do not copy your Windows `.env` or local SQLite database to the cloud.
2. Keep `PHOTOHUB_MODE=cloud` and `PHOTOHUB_ALLOW_HTTP=false`. Do not enable outbound studio sharing on the cloud installation.
3. In cPanel Terminal, from `/home/projectw/repositories/photohub`, run:

   ```sh
   php artisan migrate --force
   php artisan optimize:clear
   ```

4. Keep the document root pointing at `public`. Existing Laravel scheduler maintenance may remain for expiry/reminders/pruning; remove any separate legacy `photohub:sync` cron entries.
5. This refactor does not bypass HTTP 403 hosting rules or repair server outages. If a file is refused, inspect the hosting/WAF audit logs for that request and address its actual cause. Other files and unrelated sharing actions remain usable.

## Verification and limits

Before the studio identity extension, the full suite passed 94 tests and 589 assertions. See the updated verification report below. PHP syntax checks, JavaScript syntax checks and Blade compilation passed. `php artisan optimize:clear` was run after the local migration and final checks.

Automated tests cover offline module access and gallery creation without cloud jobs, preview-only upload, original preservation, browser continuation, file-level failures/resume, independent preview/final states, manual UUID selection retrieval/conflicts, delivery links, removal safety, quota, expiry, tenant isolation, token revocation/HTTPS, legacy-link preservation and additive migration behavior.

Live checks against the existing Hybrid Gallery confirmed API health, successful manual selection retrieval, completed final-delivery sharing under the earlier finished-photo approval, unchanged existing links and HTTP 200 for both client pages. Live preview-upload verification was blocked by automatic approval review pending specific permission to export previews. Preview file-transfer behavior is verified with HTTP-faked integration tests, not claimed as a successful live preview upload. Cloud deployment has not been performed from this workspace.


## Studio identities and responsive feedback (September 11 update)

- `CloudStudioService`, `StudioCloudConnection`, `CloudConnectionController` and `settings/cloud-connection.blade.php` provide one encrypted connection per local business, status, last successful check, Test Connection and credential recovery. Saved tokens are never rendered or flashed into validation input.
- Migration `2026_09_11_000200_add_studio_cloud_connections.php` adds the connection table and cloud identity fields. It backfills all existing local studios without network access. The previous environment token is copied only to its explicitly bound local business. Future studios obtain the same persistent local identity lazily. Neither migration deletes business/customer/gallery/photo data; rollback intentionally retains identity metadata.
- First explicit sharing checks existing credentials or calls `POST /api/share/v1/studios/register`. The cloud creates only a Business identity and a hashed API credential, with the existing 3 GB quota. Registration creates no owner, staff, customer, booking or finance records. Gallery sharing retains the existing anonymous placeholder customer required by the gallery schema; it does not copy local customer records. Existing credential holders use the old authenticated health API and do not register duplicate studios.
- A unique persistent UUID and encrypted registration secret make registration retries reuse the same identity. Cloud stores secret/token hashes; recovery requires the original secret. Names and email addresses never prove ownership. Revoked/suspended identities cannot bypass restrictions by registering again.
- `CloudApiService` now resolves credentials by the requested local business. `OnlineGalleryService` and navigation support all studios. Cloud health returns the authenticated cloud studio ID; replacement credentials must match the previously verified identity.
- Super Admin studio details show identity type, ID, registration time, cloud activity, app version and token status, with credential revocation. Existing activation/suspension, subscription and quota controls remain available. For secure administrator recovery use the existing `php artisan photohub:studio-token BUSINESS_ID --revoke` command in the cloud terminal and transfer its newly issued secret privately into local Cloud Connection recovery. Never paste tokens into tickets or normal UI.
- `feedback.js`, `feedback.css` and the shared Blade feedback component provide accessible animated toasts, busy states, duplicate guards, two-second copy confirmation, gallery image skeletons and lazy loading. Uploads show byte progress for local upload and completed-file percentage for online batches; unknown-duration steps use an indeterminate bar. Customer selection is optimistic with rollback; submissions/download requests restore controls on failure. ZIP responses are buffered as a browser Blob, so very large ZIPs require corresponding browser memory.
- Existing explicit sharing services, API compatibility routes, UUID mappings, independent final/preview status, local original preservation and removal of continuous jobs remain as described above.

### Deployment needed

The local additive migration was applied after a SQLite backup at `storage/app/private/photohub-before-studio-connections-20260911-123153.sqlite`. Existing business, user, customer, gallery, photo, final-photo and selection records were compared before/after and unchanged. No local migration command remains necessary for this workspace.

Deploy the changed PHP services/controllers/models, API/web routes, middleware/config/bootstrap, both additive migrations, Blade views and public JS/CSS to Truehost. Preserve cloud `.env`, `APP_KEY`, database and storage. In cPanel Terminal from the application root run:

```sh
php artisan migrate --force
php artisan optimize:clear
```

Keep `PHOTOHUB_MODE=cloud`, `PHOTOHUB_ALLOW_HTTP=false`, and `PHOTOHUB_REGISTRATION_ENABLED=true`. Ensure HTTPS routes `/api/share/v1/studios/register` and `/api/sync/v1/*` reach Laravel through the hosting rules. Registration is rate limited; it can be disabled with the registration setting. Do not copy the local database or environment to Truehost. Existing clients retain their URLs; studios without credentials need this cloud deployment before automatic registration can succeed. No cloud deployment was performed here.

Locally keep `PHOTOHUB_MODE=local`, `PHOTOHUB_CLOUD_ENABLED=true`, and the common `PHOTOHUB_CLOUD_URL`. The legacy ID/token variables are only compatibility input for the previously connected studio; new studios do not need separate environment credentials. Sharing needs no queue worker or sync cron.


### Final verification

- Full Laravel suite: **99 tests passed, 626 assertions**.
- PHP syntax: all 165 application/migration files passed; Blade compilation passed; changed JavaScript syntax checks passed.
- Headless Edge fixture test passed: sharing percentage, duplicate submission guards, error restoration, two-second copied state, customer submission error feedback, download preparation guard and mobile toast bounds. Run with `node tests/browser/feedback.cjs` (Node 22+ and Edge/Chrome; set `PHOTOHUB_BROWSER` if needed).
- Added tests cover lightweight/idempotent registration, secret ownership, revoked credentials, existing/future studio isolation, encrypted/hidden local credentials, failed-registration offline safety, legacy credential reuse, cloud ID mismatch rejection and settings recovery.
- Local database backup and preservation comparison passed. No customer/gallery data was removed. Cloud registration and uploads were tested with fake HTTP responses; this does not claim the new endpoint is deployed on Truehost.


### Slow cloud response correction

A live read-only health check reproduced cURL timeout 28 with the previous 10-second request limit: HTTPS connected in about 1.2 seconds, but no response arrived within 10 seconds. A follow-up check with a 30-second limit returned HTTP 200 in about 7.4 seconds. Both saved studio credentials were independently accepted by the cloud.

Cloud metadata/connection requests now allow 30 seconds, TCP/TLS connection establishment 10 seconds, and photo uploads 60 seconds. The local explicit-operation execution budget and gallery lock were increased together to cover the bounded series of requests and avoid overlapping retries. Requests are not automatically replayed. Transport failures name the affected step and log only the studio ID, step, numeric cURL code and timeout; tokens, request payloads and client URLs are excluded.

This change tolerates slow cloud responses; it does not claim to fix the underlying hosting latency. If failures continue, check the affected step in local `storage/logs/laravel.log` and corresponding cloud request/hosting logs. Update the local app for the timeout fix; deploy the same commit to Truehost to keep both installations on the same version.
