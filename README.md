# FurniCraft

FurniCraft is a Laravel web application for Lance Furniture Shop. It gives authenticated customers a guided journey through catalog browsing, saved preferences, computer-vision room analysis, hybrid furniture recommendations, scoped Gemini chat, 2D room planning, and reservation requests. Administrators manage catalog records, customer access, reservations, and confirmation notifications.

## Scope

- Exactly two roles: Customer and Administrator/Owner
- Guests can browse the home page and public catalog; customer planning, favorites, reservations, and all administrator features require sign-in
- The protected customer flow is sign-in → save preferences → optionally upload and analyze a room photo → generate recommendations and chat → submit a reservation request
- Content-based and collaborative recommendation scoring with an optional Gemini-generated explanation
- Gemini-assisted chatbot restricted to the current recommendation and Lance Furniture Shop catalog facts
- The customer-facing room upload is photo-first and does not ask for room dimensions. Gemini may estimate width and depth; local planning guidance is used when AI is not authorized or unavailable. Legacy optional dimensions remain supported by the backend.
- Gemini room analysis and furnished-room image generation run in the database queue; the preview page polls for completion and continues to show the local 2D overlay while image generation runs
- Reservation requests only; no payment, delivery tracking, inventory, or financial management
- Queued email verification, password-reset, reservation-confirmation, and beta feedback email
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
php artisan queue:work --tries=3 --timeout=540
php artisan schedule:work
```

The development server is available at `http://127.0.0.1:8000`. `DatabaseSeeder` seeds demo furniture only and refuses to run in production. It does not create demo accounts. For an explicitly configured beta deployment, set `BETA_SEED_ACCOUNTS=true` and populate `BETA_ADMIN_NAME`, `BETA_ADMIN_EMAIL`, `BETA_ADMIN_PASSWORD`, `BETA_CUSTOMER_NAME`, `BETA_CUSTOMER_EMAIL`, and `BETA_CUSTOMER_PASSWORD` through the host's secret manager, then run `php artisan db:seed --class=BetaTestAccountsSeeder --force`. The seeder does not print passwords or replace an existing account. Only Customer and Administrator roles exist; staff accounts are not supported by authorization or workflow code.
## Production deployment and recovery

FurniCraft is currently a locally run prototype. No hosting target, public HTTPS URL, real mail provider, backup destination, restore drill, or supervised production services have been verified. Do not describe the system as production deployed until these steps are completed on the target host.

## Beta test preflight

- Use an isolated beta database and test accounts; do not expose local development settings to testers. Start from the credential-free [.env.beta.example](.env.beta.example) and configure deployment secrets through the host's secret-management process. Local startup scripts bind only to `127.0.0.1`; shared beta hosting requires `APP_DEBUG=false` and HTTPS.
- Decide whether beta should exercise room-preview v2. If enabled, start with `ROOM_PREVIEW_V2_ROLLOUT_PERCENT=5` or less, verify GD in both web and worker PHP runtimes, inspect fallbacks, then increase gradually. Use the beta template for deployment settings rather than copying a developer's environment values.
- Run `php artisan system:readiness`, then independently verify a live queue worker, scheduler, real email delivery, Gemini access, and external `/up` and TLS checks. The readiness command cannot prove those services are running.
- Follow the cross-browser, keyboard, interrupted-network, Gemini-outage, concurrent-reservation, and restore-drill checks in [browser-acceptance.md](docs/browser-acceptance.md) before inviting representative customers.

### Host configuration

- Use a maintained PHP 8.2+ host with required Laravel extensions, MySQL/MariaDB, HTTPS, and a web server whose document root is the app's `public` directory.
- Create a dedicated least-privilege database user. Configure deployment environment variables with `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, a generated stable `APP_KEY`, MySQL credentials, a real delivery mailer, and `QUEUE_CONNECTION=database` with `DB_QUEUE_RETRY_AFTER=600`.
- Keep `storage/app/private` persistent, writable by the app, outside the web root, and included in encrypted backups. Never expose `.env`, database dumps, logs, or private room photos from the web server.
- Run migrations as a release step with `php artisan migrate --force`. Do not run `migrate:fresh` on a live database. Keep a supervised queue worker running with `php artisan queue:work --tries=3 --timeout=540`; restart it after deployments. Run `php artisan schedule:run` every minute through the host scheduler so retention jobs execute.
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

The recommendation algorithm, chatbot, and room workflow have deterministic local fallbacks, so the system remains usable without an API key. To enable Gemini, add these values to `.env`:

```env
GEMINI_API_KEY=your_key_here
GEMINI_MODEL=gemini-3.8-flash
GEMINI_FALLBACK_MODEL=gemini-3.6-flash
GEMINI_IMAGE_MODEL=gemini-3.1-flash-image
GEMINI_IMAGE_FALLBACK_MODEL=gemini-2.5-flash-image
```

The model name is configurable so it can be changed without modifying application code. The hybrid recommendation ranking is computed by FurniCraft; Gemini receives the ranked, in-system product facts to explain results. Chat prompts explicitly limit answers to the supplied catalog and recommendation data.

## Email configuration

Email verification, reservation-confirmation notifications, and beta feedback email use Laravel's queue. Password-reset email is sent synchronously by its controller. Configure a real mail provider and `ADMIN_FEEDBACK_EMAIL` for deployment:

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

Keep `php artisan queue:work` running so queued mail is delivered. On Linux hosts, configure the scheduler to run every minute, for example `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

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
- Room-photo AI processing requires an explicit per-upload opt-in; unconsented images use local guidance only. New photos and generated previews are privately stored, metadata-stripped, and scheduled for removal after `ROOM_IMAGE_RETENTION_DAYS` (default 30).
- The retention task is scheduled in Laravel. Run `php artisan schedule:work` locally, or configure the standard Laravel scheduler cron entry in production; also run `php artisan room-images:secure-legacy` once after upgrading older installations.
- The privacy notice describes the application’s controls and external Gemini transfer. It is not a certification or legal compliance determination.

## Known beta limitations

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
