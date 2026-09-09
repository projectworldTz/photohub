**Storage quota and gallery expiry implementation report ? 8 September 2026**

Implemented in the existing Laravel 12 application and applied to the local SQLite database. LensCraft Studio uses **18,202,046 bytes (17.36 MB) / 3 GB** across **28 retained files**.

1. **Files changed**

   The complete file list appears below. Changes cover storage services, the two upload workflows, authorization, gallery access and lifecycle, existing Bootstrap views, console commands, migrations and tests.

2. **Database migrations**

   `2026_09_08_000100_add_studio_storage_and_gallery_lifecycle.php` adds `businesses.storage_limit_bytes`, the indexed `storage_files` inventory, and gallery `expired_at` / `expiry_notice_days` fields. It reuses the existing `expires_at` column and adds an index. New accounts default to 3 GB. Existing accounts begin with a NULL allocation, which requires reconciliation before enforcement; their initialized allocation is `max(3, ceil(actual_bytes / 1073741824))` GB.

   Existing explicit gallery expiry dates remain unchanged. Existing galleries without a date receive 30 days from migration, providing a transition window for current shared links. New galleries default to creation time plus 30 days.

   `2026_09_08_000200_allow_retained_final_photo_versions.php` replaces the unique gallery/proof index with a regular index. This permits soft-deleted previous final versions to retain their proof association. Studio-locked replacement transactions leave one active final per proof.

   Both migrations were applied locally. Neither recreates, truncates or deletes existing data. Rollback explicitly requires a forward migration so retained versions and quota inventory cannot be accidentally discarded.

3. **Storage calculation**

   `storage_files` stores one entry per physical path, with its studio ID and byte size. A unique path hash prevents duplicate counting. Usage is an indexed, studio-scoped database sum; page requests do not rescan initialized studios.

   Originals, previews, thumbnails, edited deliveries and retained versions count. Branding and expense receipt uploads also use the inventory and quota checks. Expired and soft-deleted gallery/photo records do not release storage. Temporary upload staging files and generated download ZIPs are transient and excluded.

   Reconciliation reads the studio directory plus legacy paths referenced by original/final photo records, including soft-deleted records. It includes unreferenced retained files under that studio directory and deduplicates paths shared by an original and its variants. Foreign or ambiguous ownership and unsafe paths cause an error rather than silent misattribution. Reconciliation reads file sizes without moving or changing files. A later reconciliation preserves manually assigned quotas.

4. **Quota enforcement**

   Both original and final-photo upload routes use the same batch service. It checks combined original sizes, prepares all image variants in temporary files, then measures the exact total. A database transaction locks the studio, reloads its allocation and checks `used + incoming <= limit` before persisting the batch. The final check includes previews and thumbnails. New files use UUID paths and existing destinations are never overwritten.

   Quota failures return validation errors before accepting any files. A failed disk write rolls back the batch's photo records and removes only its newly written files. Any files that resist cleanup are inventoried by reconciliation. Existing subscription/trial entitlement and gallery-count limits remain; studio quotas replace subscription-plan storage limits.

   The shared physical deletion service checks studio ownership and inventory membership, then releases bytes only after the file is absent or deletion succeeds. Failed deletions retain their allocation. No permanent-delete UI or automatic photo cleanup was introduced; the existing gallery action remains archival.

5. **Super Admin adjustment**

   Studio details include usage, remaining capacity, a 1?50 GB slider, numeric GB input and a save button. Larger inherited allocations expand the slider; numeric input supports larger whole-GB allocations. The studio list shows usage, limit, percentage, gallery count and storage state. Dashboard, settings and upload views show the storage widget using existing Bootstrap colors.

   `PATCH admin/businesses/{business}/storage` validates a whole-number allocation and checks `is_super_admin` on the backend. Normal owners receive 403. The quota is excluded from Business mass assignment. Updates lock and change only the selected studio. An administrator may reduce a quota below current usage: all existing data remains available to the studio, while new uploads pause. Plan management explains that changing plan metadata does not change studio allocations.

6. **Gallery expiry**

   Gallery access is checked against the expiry timestamp on each request, including secure selection/delivery tokens, image routes, likes/selections, downloads and legacy gallery routes. Portfolio images also respect expiry. A token with a later expiry cannot bypass the gallery deadline. Protected gallery images use private, no-store responses.

   The hourly maintenance task records `expired_at` while preserving the existing selection/editing/delivery status. Runtime access checks work even if the scheduler is delayed. Photographers can still manage expired galleries, view their photos and filter the gallery list for expiry. Dates, remaining days and warnings appear in the management views; client pages display the availability date.

   Owners can extend a gallery by 7 or 30 days. Unrevoked links that followed the old gallery deadline follow the extension; independently shorter link deadlines remain restricted. The existing edit form also supports an explicit gallery expiry date. Separately revoked or manually archived/expired galleries retain those restrictions.

   The daily reminder task uses the existing queued BusinessAlert notification architecture at 7, 3 and 1 day thresholds, with deduplication and reset after extension. BusinessAlert currently delivers in-app database notifications; email delivery can be added through its existing notification channels. No reminders were sent during local deployment.

7. **Existing data protection and deployment verification**

   Before migration, a consistent SQLite backup and file-hash manifest were saved under:

   `storage/app/private/deployment-backups/storage-expiry-20260908-212434/`

   The backup contains `database.sqlite`, `before.json` and `after.json`. Verification compared all original fields of the business, user, customer, gallery and photo records, excluding only the intentional quota/lifecycle fields. Existing counts remained: 1 business, 8 users, 2 customers, 1 gallery, 8 original photo records and 1 final photo record. All 28 studio files retained identical SHA-256 hashes. No existing photo paths, contents, accounts or workflow states were changed.

   The local reconciliation initialized the studio to 3 GB, safely above its 17.36 MB usage. The local expiry maintenance command found no currently expired galleries. Final replacement now retains earlier versions instead of permanently deleting them.

8. **Tests performed**

   Final result: **53 tests passed, 218 assertions** (`php vendor/phpunit/phpunit/phpunit`). All Blade templates compiled successfully; Pint formatting and `git diff --check` passed.

   The existing regression suite and 15 new feature tests cover default allocation, exact byte arithmetic, quota rejection, studio isolation, owner authorization, UI rendering, actual variant accounting, final upload enforcement, all-or-nothing batch rollback, default expiry and public access denial, owner access after expiry, retained-file accounting, safe initialization above 8 GB, successful/failed physical deletion, foreign-file protection, reminder deduplication, extension and final replacement retention.

   Tests use an in-memory SQLite database and fake file storage. The test base now isolates seeder writes from real studio storage. Two existing fixtures were corrected: upload assertions select the uploaded gallery's photo, and the expired-entitlement fixture expires the seeded subscription as well as the trial.

   Blade compilation and code formatting were checked. Actual browser interaction and multi-process production-database load testing were not performed.

9. **Operating recommendations**

   Ensure the existing Laravel scheduler runs every minute and a queue worker processes queued notifications. Restart long-running workers through the existing process manager so they load the updated service code. Expiry access denial itself does not require the scheduler. Configure email only if email reminders are desired.

   Run `php artisan photohub:storage-reconcile` after out-of-band file changes or an interrupted worker; use `--business=ID` to restrict reconciliation. It is safe to repeat and does not reset manually assigned quotas. Run `php artisan photohub:galleries-expire` to update lifecycle markers immediately without deleting files.

   A future permanent-cleanup workflow should include explicit user confirmation, recoverability and ownership checks, and use StorageQuotaService for physical deletion. Until then, archiving and replacement intentionally retain files and storage usage.

   Benchmark large batches under the intended web-server timeouts. Exact batch sizing prepares variants during the upload request. For higher throughput, staged background jobs can preserve the same quota checks. Test concurrent uploads against the production database before scaling; SQLite may return write-lock contention errors instead of allowing simultaneous writers. A process crash between filesystem and database operations requires reconciliation of retained files.

**Complete changed-file list**

- `app/Http/Controllers/AdminBusinessController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/ExpenseController.php`
- `app/Http/Controllers/FinalDeliveryController.php`
- `app/Http/Controllers/GalleryController.php`
- `app/Http/Controllers/GalleryWorkflowController.php`
- `app/Http/Controllers/GuestSelectionController.php`
- `app/Http/Controllers/PublicBusinessController.php`
- `app/Http/Controllers/PublicGalleryController.php`
- `app/Http/Controllers/SettingsController.php`
- `app/Http/Controllers/SuperAdminController.php`
- `app/Models/Business.php`
- `app/Models/Gallery.php`
- `app/Models/GalleryAccessToken.php`
- `app/Services/FinalPhotoService.php`
- `app/Services/GalleryAccessService.php`
- `app/Services/GalleryExpiryService.php`
- `app/Services/PhotoProcessingService.php`
- `app/Services/PhotoUploadService.php`
- `app/Services/StorageQuotaService.php`
- `app/Services/SubscriptionLimitService.php`
- `database/migrations/2026_09_08_000100_add_studio_storage_and_gallery_lifecycle.php`
- `database/migrations/2026_09_08_000200_allow_retained_final_photo_versions.php`
- `docs/storage-quota-gallery-expiry-report.md`
- `resources/views/admin/business-show.blade.php`
- `resources/views/admin/index.blade.php`
- `resources/views/admin/plans.blade.php`
- `resources/views/components/gallery-expiry.blade.php`
- `resources/views/components/storage-usage.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/galleries/form.blade.php`
- `resources/views/galleries/index.blade.php`
- `resources/views/galleries/links.blade.php`
- `resources/views/galleries/show.blade.php`
- `resources/views/galleries/workflow.blade.php`
- `resources/views/public/delivery.blade.php`
- `resources/views/public/gallery.blade.php`
- `resources/views/public/selection.blade.php`
- `resources/views/settings/index.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Feature/PhotoDeliveryTest.php`
- `tests/Feature/StorageQuotaExpiryTest.php`
- `tests/Feature/SubscriptionLimitTest.php`
- `tests/TestCase.php`
