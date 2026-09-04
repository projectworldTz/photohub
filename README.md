# PhotoHub

PhotoHub is a Laravel 12 multi-tenant photography business platform covering studio CRM, bookings and shoots, finance, private proof/final galleries, paid photo delivery, print orders, customer portals, contracts, tasks, reports, portfolios, subscriptions, notifications, support, and super administration.

## Requirements and installation

Use PHP 8.2+, Composer 2, MySQL 8+/MariaDB 10.6+ (SQLite is configured locally), and the PHP GD and ZIP extensions.

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

Open `http://127.0.0.1:8000`. With XAMPP, configure the web root as this project's `public` directory.

## Demo accounts

All development accounts use `PhotoHub2026!`:

| Role | Email |
|---|---|
| Super administrator | admin@example.com |
| Business owner | owner@example.com |
| Manager | manager@example.com |
| Photographer | photographer@example.com |
| Editor | editor@example.com |
| Receptionist | receptionist@example.com |
| Accountant | accountant@example.com |
| Customer portal | customer@example.com |

These credentials are seed data only and must never be used in production.

## Configuration and operation

For MySQL, set the `DB_*` values in `.env`, create the database, then run `php artisan migrate --seed`. Run queued work with `php artisan queue:work`. In production, invoke `php artisan schedule:run` every minute. Configure `MAIL_*` before enabling mail and choose a `FILESYSTEM_DISK` appropriate to the environment.

```bash
vendor/bin/pint
php artisan test
php artisan route:list
```

Public studio pages are available at `/p/{business-slug}` and galleries at `/gallery/{gallery-code}`. Private galleries require their PIN; original files remain in private storage and are streamed only after authorization. Run `php artisan schedule:list` to verify the recurring expiry and cleanup tasks.

## Private selection and final delivery workflow

Customers do not need an account for photo selection or final delivery. After a shoot is completed, open the shoot and choose **Create Selection Gallery**. Upload proof photos; PhotoHub processes previews and creates a secure `/select/{token}` link with a one-calendar-year default expiry. Open **Selection & delivery** on the gallery to copy the link, share it through WhatsApp, regenerate it, revoke it, or change its expiry.

Customer selections save immediately. Submission locks the selection and notifies studio staff. The photographer can view and download selected originals, reopen the selection, mark editing started, and upload final edited photos. Files are matched by original filename or manually against a selected proof. After checking missing finals, publishing creates a separate `/delivery/{token}` link. That link lets the customer view and download individual, selected, or all final photos without login. Originals and final high-resolution files remain private and every download is authorized through the application.

For production set `APP_ENV=production`, `APP_DEBUG=false`, use HTTPS, configure the database/mail/queue, run `php artisan optimize`, and make `storage` and `bootstrap/cache` writable by the web server.

## Production operations

Run at least one persistent database-queue worker. Supervisor or systemd should restart it on failure:

```bash
php artisan queue:work database --sleep=2 --tries=3 --timeout=300
```

Run the scheduler every minute on Linux:

```cron
* * * * * cd /var/www/photohub && php artisan schedule:run >> /dev/null 2>&1
```

On Windows Server, create a Task Scheduler job that executes `php artisan schedule:run` from the project directory every minute. The scheduler expires galleries, quotations, invoices and subscriptions, sends reminders, and removes temporary ZIP files.

Before deployment, configure MySQL, mail, queue and filesystem values; then run:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Back up the database and the private storage disk together. Originals, previews, branding assets and receipt attachments are private and must not be served directly by the web server. Cloud disks can use the standard Laravel S3 variables in `.env`.

## Verification

```bash
vendor/bin/pint --test
php artisan test
php artisan route:list --except-vendor
php artisan event:list
php artisan schedule:list
```

The automated suite covers tenant isolation, role restrictions, booking conflicts, invoice calculations, reversals, receipts, gallery access, proof limits, paid downloads, ZIP contents, QR generation, order pricing, subscription limits and customer portal isolation.
