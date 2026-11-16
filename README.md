# FurniCraft

FurniCraft is a Laravel web application for Lance Furniture Shop. It gives authenticated customers a guided journey through catalog browsing, saved preferences, computer-vision room analysis, hybrid furniture recommendations, scoped Gemini chat, 2D room planning, and reservation requests. Administrators manage catalog records, customer access, reservations, and confirmation notifications.

## Scope

- Exactly two roles: Customer and Administrator/Owner
- Guests can browse the home page and public catalog; customer planning, favorites, reservations, and all administrator features require sign-in
- The protected customer flow is sign-in → save preferences → optionally upload and analyze a room photo → generate recommendations and chat → submit a reservation request
- Content-based and collaborative recommendation scoring with a locally generated summary
- Gemini-assisted chatbot restricted to the current recommendation and Lance Furniture Shop catalog facts
- The customer-facing room upload is photo-first and does not ask for room dimensions. Gemini may estimate width and depth; local planning guidance is used when AI is not authorized or unavailable. Legacy optional dimensions remain supported by the backend.
- Gemini room analysis and furnished-room image generation run in the database queue; the preview page polls for completion and continues to show the local 2D overlay while image generation runs
- Reservation requests only; no payment, delivery tracking, inventory, or financial management
- Queued email verification, password-reset, reservation-confirmation, and feedback email
- Password-reset codes stored as hashes, valid for 15 minutes, single-use, and request-rate-limited
- Responsive Bootstrap interface for phone and desktop browsers

## Requirements

- PHP 8.2 or newer
- Composer 2
- Node.js and npm for building frontend assets
- A local MySQL or MariaDB server for development and deployment
- PHP extensions normally enabled by XAMPP for Laravel (`pdo_mysql`, `mbstring`, `openssl`, `fileinfo`)
- Internet access for Gemini and the configured email provider. Bootstrap is bundled through Vite for the frontend.

## Set up a local clone

Clone the repository and run the following commands from its root. Copy the example environment file, install dependencies, and generate an application key. The committed template is production-oriented, so switch your local copy to local mode before starting development:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
```

Set `APP_ENV=local`, `APP_DEBUG=true`, and `APP_URL=http://127.0.0.1:8000` in your local `.env`. Keep `.env` private and never commit it.

Create a local MySQL/MariaDB database using `utf8mb4`, then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Use a dedicated database account outside a disposable local environment.

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=furnicraft
DB_USERNAME=root
DB_PASSWORD=
```

Install the schema and catalog, build the frontend assets, and create the first administrator interactively:

```powershell
php artisan migrate --seed
php artisan storage:link
npm install
npm run build
php artisan furnicraft:admin:create
```

Start the local application on loopback. On Windows with XAMPP, use `.\start-system.ps1`; otherwise run the web server, queue worker, and scheduler in separate terminals:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work --queue=mail --tries=3 --timeout=60
php artisan queue:work --queue=ai,default --tries=3 --timeout=540
php artisan schedule:work
```

The development server is available at `http://127.0.0.1:8000`. `DatabaseSeeder` seeds demo furniture only and refuses to run in production. It does not create accounts. `BetaTestAccountsSeeder` is for local or isolated staging only and refuses to run in production. Create the first production owner account interactively with `php artisan furnicraft:admin:create`; never publish test credentials. Only Customer and Administrator roles exist; staff accounts are not supported by authorization or workflow code.
## Production deployment and recovery

Release status: the application code and deployment guidance are prepared for a production release, but no production host, public HTTPS URL, live mail delivery, backup destination, restore drill, or supervised production services have been verified. Complete the release checks below on the actual host before opening public traffic.

## Public production release

- Use a dedicated production database and host. Start from the [production environment template](.env.production.example), replace every placeholder through the host's secret manager, and never copy a developer's `.env` or publish test credentials.
- Set the real HTTPS `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, secure session settings, least-privilege database credentials, real mail credentials, and `ADMIN_FEEDBACK_EMAIL`. Leave optional AI disabled until consent, privacy language, quotas, and billing are approved.
- Import and verify the shop's approved product catalog and images through the admin interface; sample/demo seed data is never loaded into production.
- Have the privacy notice and reservation terms reviewed for the real deployment, including the shop's privacy contact, data handling, and retention practices.
- Keep room-preview v2 disabled until its image runtime, fallback behavior, privacy controls, and production resource usage have been explicitly reviewed.
- Run `php artisan system:readiness`, then independently verify a supervised queue worker, scheduler, real email delivery, external `/health` and `/up`, TLS, and private room-image access. The readiness command cannot prove those services are running.
- Follow the cross-browser, keyboard, interrupted-network, AI-outage, concurrent-reservation, and restore-drill checks in [browser-acceptance.md](docs/browser-acceptance.md) before opening public traffic.

### Host configuration

- Use a maintained PHP 8.2+ host with required Laravel extensions, MySQL/MariaDB, HTTPS, and a web server whose document root is the app's `public` directory.
- Create a dedicated least-privilege database user. Configure deployment environment variables with `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, a generated stable `APP_KEY`, MySQL credentials, a real delivery mailer, and `QUEUE_CONNECTION=database` with `DB_QUEUE_RETRY_AFTER=600`.
- Keep `storage/app/private` persistent, writable by the app, outside the web root, and included in encrypted backups. Never expose `.env`, database dumps, logs, or private room photos from the web server.
- Run migrations as a release step with `php artisan migrate --force`. Do not run `migrate:fresh` on a live database. Supervise separate mail and AI workers using the commands above, and restart both after deployments. Run `php artisan schedule:run` every minute through the host scheduler so retention and recovery tasks execute.
- Before opening traffic, run `php artisan system:readiness` and review the administrator’s Deployment readiness page. Also verify real email delivery, `/up`, queue processing, logs, and TLS from outside the host; the readiness command cannot prove those external behaviors.

### Backup and restore procedure

1. Choose an encrypted backup location outside the web root with access restricted to operators. Set and document retention requirements with the shop; do not store backups under `public` or commit them to Git.
2. Put the app in maintenance mode, pause the queue worker, and take a consistent MySQL/MariaDB dump plus a copy of `storage/app/private`. Back up deployment secrets through the approved secret-management process. Example Windows/XAMPP dump command (replace the output directory and database name):

   ```powershell
   mysqldump --single-transaction --routines --triggers --host=127.0.0.1 --user=furnicraft_backup --password furnicraft --result-file="D:\\FurniCraftBackups\\furnicraft-YYYYMMDD.sql"
   Copy-Item .\\storage\\app\\private "D:\\FurniCraftBackups\\private-YYYYMMDD" -Recurse
   ```

   Supply the backup user password interactively; do not put it in shell history. Encrypt and move both artifacts to the approved off-site destination, verify their checksums, then restore service and resume the worker.
3. For a restore drill, use an isolated MySQL database and a non-public staging copy. Restore the SQL dump, restore `storage/app/private` to the corresponding application storage directory, apply any newer migrations, then verify login, catalog, reservation history, private room-image access, queue processing, and email delivery. Never test restore by overwriting the live database.
4. Record the drill date, operator, duration, and any data loss or errors. A backup is not considered verified until a restore has succeeded. Set the schedule, retention count, recovery-time objective, and recovery-point objective with the hosting operator before launch.

The built-in `/up` endpoint is a basic application health check. `/health` checks database, cache, and queue connectivity without returning configuration secrets. The admin Deployment readiness page and `system:readiness` command check configuration; production still needs host-level uptime monitoring, exception monitoring, supervised workers, and tested backup alerts.

## Gemini configuration

Recommendation ranking and summaries run locally. Room analysis and previews have local fallbacks, and the chatbot can use local catalog answers, so the system remains usable without an API key. To enable Gemini for consented room-photo analysis, furnished-room previews, and chatbot answers, add these values to `.env`:

```env
GEMINI_API_KEY=your_key_here
GEMINI_MODEL=gemini-3.8-flash
GEMINI_FALLBACK_MODEL=gemini-3.6-flash
GEMINI_IMAGE_MODEL=gemini-3.1-flash-image
GEMINI_IMAGE_FALLBACK_MODEL=gemini-2.5-flash-image
```

The model name is configurable so it can be changed without modifying application code. FurniCraft computes hybrid recommendation rankings and summaries locally. Generating recommendations does not call Gemini. When a customer asks a chatbot question that needs an AI answer, Gemini may receive the question and relevant recommended-product and active-catalog facts. Chat prompts explicitly limit answers to that supplied data.

## Email configuration

Submitting a reservation saves the pending request without sending a receipt email. Approving it automatically queues one approval email to the customer. The email includes the reservation reference, furniture, and instructions to visit Lance Furniture Shop or call the number set in `FURNICRAFT_SHOP_PHONE`. Set that variable to the shop's contact number in `.env`. Keep the dedicated `mail` queue worker running for delivery; the admin reservation list shows the email's queued, sent, or failed status.

Email verification, password-reset, reservation-confirmation notifications, and feedback email use Laravel's queue. Configure a real mail provider and `ADMIN_FEEDBACK_EMAIL` for deployment:

```env
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="FurniCraft"
ADMIN_FEEDBACK_EMAIL=admin@example.com
QUEUE_CONNECTION=database
```

Keep the dedicated mail and AI workers running using the commands above. The Windows queue-worker helper starts both workers. On Linux hosts, configure the scheduler to run every minute, for example `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

## Test and quality commands

```powershell
php artisan test
php artisan view:cache
vendor\bin\pint --test
```

The feature suite verifies public and protected access, required preferences and room analysis, safe post-login return, role authorization, partial-word autocomplete, hybrid recommendations, Gemini dimension estimates, catalog-only room previews, hashed reset codes, rate limiting, and reservation-confirmation notifications.

## Important privacy notes

- Do not place people, documents, addresses, or other personal material in room photos.
- Reset codes are never stored in plaintext.
- Individual view and favorite events are used for recommendations and aggregate ranking; the customer interface does not expose another customer’s activity.
- Configure HTTPS, production mail, backups, retention rules, and a privacy notice before real customer use.
- Room-photo AI processing requires an explicit per-upload opt-in; unconsented images use local guidance only. New photos and generated previews are privately stored, metadata-stripped, and scheduled for removal after `ROOM_IMAGE_RETENTION_DAYS` (configuration/template default 90; existing deployments retain their configured value).
- The retention task is scheduled in Laravel. Run `php artisan schedule:work` locally, or configure the standard Laravel scheduler cron entry in production; also run `php artisan room-images:secure-legacy` once after upgrading older installations.
- The privacy notice describes the application’s controls and external Gemini transfer. It is not a certification or legal compliance determination.

## Known release limitations

- A single room photo only supports approximate scale and placement; confirm physical measurements with the shop before reserving.
- Gemini is optional. Without `GEMINI_API_KEY`, room analysis and chat use local guidance; provider outages also fall back locally.
- Reservations are inquiries only. The system has no payment, inventory, delivery, or order-fulfillment workflow.
- Only Customer and Administrator roles exist. Staff-specific permissions and account workflows are not implemented.
- A live queue worker and scheduler, real email delivery, HTTPS, backups, and restore drills must be verified on the target host; repository checks cannot prove those external services.
- Feedback remains saved when its email cannot be queued; the submitter sees a warning.




## Automatic 2D room preview

The room preview loads the selected catalog cutout and the uploaded room image into a single static canvas. Gemini analyzes normalized room geometry, openings, scale clues, obstructions, image quality, and lighting. A deterministic client-side placement engine estimates perspective scale, samples safe positions by furniture placement type, and keeps the best result from up to three consented Gemini self-check iterations. Missing catalog dimensions block the preview. Low-quality or uncertain analysis falls back to a rough local estimate with an actionable photo tip; no image is described as an exact measurement.

Room analyses are stored in room_images.analysis and cached by the processed photo SHA-256. Preview iteration history is stored in room_preview_generations.placement_json; reason-code telemetry is recorded in ai_service_events without storing room images. The preview verification endpoint is authenticated, CSRF protected, rate limited, and only accepts requests for an active product with complete dimensions and a photo for which the customer granted Gemini consent.

Run the deterministic geometry unit checks with npm run test:room-preview. The existing Laravel feature suite is run with php artisan test. Gemini settings remain in the existing .env keys GEMINI_API_KEY, GEMINI_MODEL, and GEMINI_FALLBACK_MODEL; the API key is only read by the server.

Known limitations: a single room photo cannot reveal exact depth or hidden obstacles. Perspective and furniture scale remain estimates, product images should be tightly cropped transparent PNG cutouts for the cleanest composite, and room-fit claims must be confirmed against measured dimensions before reserving.

See [beta-hardening.md](docs/beta-hardening.md) for additive migrations, separate mail/AI workers, recovery tasks, aggregate AI request limits, monitoring, and rollback. Apply migrations before starting the updated application. Pause both workers and the scheduler for consistent backups.
