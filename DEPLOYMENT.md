# FurniCraft production deployment

Use [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) to verify mail, queue processing, scheduling, backup and restore, and rollback on the real host.

## Server requirements

- Supported PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `xml`, `ctype`, `tokenizer`, and `json` extensions.
- Composer 2, MySQL/MariaDB, a persistent private writable storage volume, HTTPS, and a web server rooted at Laravel's `public/` directory.
- Node.js/npm only when rebuilding Vite assets during a release.
- A supervised database queue worker and a scheduler process.

## Environment setup

1. Deploy the repository to a private application directory and point the web server document root to `public/`.
2. Install production dependencies and create the deployment environment from the credential-free [production template](.env.production.example). Keep secrets in the host's secret manager; never commit `.env`.

   ```sh
   composer install --no-dev --optimize-autoloader
   cp .env.production.example .env
   ```

3. Set `APP_ENV=production`, `APP_DEBUG=false`, the real HTTPS `APP_URL`, a generated stable `APP_KEY`, database credentials, mail-provider credentials, `ADMIN_FEEDBACK_EMAIL`, and `QUEUE_CONNECTION=database`. If TLS terminates at a reverse proxy, set `TRUSTED_PROXIES` to its exact IP/CIDR values. Keep `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, and `SESSION_SAME_SITE=lax`.
4. Leave `FILESYSTEM_DISK=local` for private room images unless S3 is configured. Set `PRIVATE_STORAGE_ROOT` to the absolute path of a persistent, private, writable volume outside `public/`. Create that directory on the host, give the web and queue processes access, and verify at least 5 GB of free space there with `df -h` (provision more for expected photo growth and monitor free space). The readiness threshold is fixed at 5 GB; changing an environment value cannot create disk capacity. `AWS_BUCKET`, `AWS_ACCESS_KEY_ID`, and `AWS_SECRET_ACCESS_KEY` are required only when S3 or SQS is selected. `GEMINI_API_KEY` is optional; missing keys and provider failures use local fallbacks.
5. Create a least-privilege database, then run:

   ```sh
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan storage:link
   ```

6. Create the first owner account interactively with `php artisan furnicraft:admin:create`. Do not create test or demo accounts in production. `DatabaseSeeder` and `BetaTestAccountsSeeder` are not for production use. Add and verify the real furniture catalog through the admin interface before opening public traffic. Staff-role accounts are not supported.
7. Have the privacy notice and customer-facing reservation terms reviewed and approved for the shop's real data practices. Confirm the privacy contact and retention rules before launch.
8. Run `php artisan system:readiness`, check `/health` and `/up`, then verify real email delivery, queue processing, HTTPS, private room-image access, and the scheduler before opening public traffic.

## Queue and scheduler

Run the worker under Supervisor, systemd, or the host's process manager. Restart it on deploy:

```sh
php artisan queue:work --queue=mail --tries=3 --timeout=60
php artisan queue:work --queue=ai,default --tries=3 --timeout=540
php artisan queue:restart
```

Schedule `php artisan schedule:run` every minute using the host scheduler. Keep the worker timeout shorter than the queue connection's `retry_after` value. Review failed jobs with `php artisan queue:failed` and retry only after checking the cause.

Registration verification metadata is pruned hourly after a code has been used or expired for `REGISTRATION_VERIFICATION_RETENTION_HOURS` (default 24, minimum 1). Preview the eligible count with `php artisan registration-codes:prune --dry-run`. The cleanup removes only eligible verification records, not accounts or still-usable codes. Keep the scheduler running and monitor its success; adding a schedule does not install the host scheduler. Before a manual production cleanup, follow the backup/restore procedure below. Restore the affected verification table from a protected backup if data recovery is necessary; increasing the retention setting cannot recover deleted rows.

## Backups and recovery

- Back up the MySQL/MariaDB database and the entire `storage/app/private` room-image directory together using encrypted storage outside the web root. Back up deployment secrets through the host's secret-management process, not as an exposed file artifact.
- Agree on backup frequency, retention, recovery point objective, and recovery time objective with the operator. Restrict backup access and monitor completion.
- Test restores in isolated staging. Restore the database and private files, apply migrations, then check authentication, catalog, reservations, photo privacy, queue processing, and outgoing mail. Never use the live database as a restore test target.
- Record each restore drill's date, duration, operator, and issues. A backup is not verified until the restore succeeds.

## Production monitoring

Laravel writes daily rotating logs by default (`LOG_CHANNEL=daily`, 14 days). Review `storage/logs` and failed jobs. Configure an exception tracker such as Sentry or Flare before launch; neither is currently configured. `/health` checks database, cache, and queue availability, but does not replace external uptime monitoring or worker/scheduler supervision.

## Rollback

Keep the previous release available and roll application code back through the deployment process. Before any down migration or destructive data operation, take a verified backup and review the migration's impact. Do not run `migrate:fresh` or rewrite repository history on a production database.

See [beta-hardening.md](docs/beta-hardening.md) for additive migrations, separate workers, recovery tasks, aggregate AI limits, and rollback. Pause both workers and the scheduler for consistent backups.
