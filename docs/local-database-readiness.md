# Local database preparation — 4 November 2026

This records checks against the existing Windows-local FurniCraft database. It is not approval to open production traffic.

## Completed

- Created an access-restricted backup outside the public web root, in a Git-ignored directory: `storage/app/ops-backups/20261104-223901`.
- Backed up the original environment file, database SQL, and private files before changes. The environment backup contains secrets; keep the whole backup directory private.
- Restored the pre-change SQL into a separate temporary database. All 29 table row counts matched. Extracted and checked SHA-256 hashes for all 390 private files. Removed only this temporary restore database and extraction afterward.
- Applied the three November 1 migrations: notification dispatch recovery columns, pending file deletion records, and AI usage buckets. No migrations remained pending.
- Switched the application's local database account from root to a dedicated account restricted to `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on the application database. The password is stored only in the local environment configuration and its private backup.
- Disabled application debug output and configured `ADMIN_FEEDBACK_EMAIL=lancefurniture15@gmail.com`. The production environment example has the same recipient.
- Created another verified backup in the `after-updates` subdirectory. Restored all 31 tables into an isolated temporary database and compared row counts; restored and checked all 390 private files by hash. Backup checksums and counts are in each backup's `verification.json`.
- Confirmed the existing data counts after migration: 5 users, 25 products, 10 reservations, and 6 room photo records.
- Confirmed HTTP 200 responses for local `/health`, `/login`, and `/catalog`.
- Restarted both local queue workers with the restricted account. Both processed harmless file-writing canary jobs. Removed the canary files afterward. No test email or Gemini request was sent.

## Future maintenance

The runtime account deliberately cannot create, alter, or drop tables. Future migrations and administrative database backups/restores need a separate authorized database administrator/deployment connection. Do not grant global or schema-changing privileges to the runtime account merely to run a migration. Keep credentials out of Git and command history.

Before another schema change, create a new consistent database/private-file backup. Verify recovery in an isolated database and directory. Retain the pre-change backup until the new release is verified. The migrations applied here are additive; do not use a blind `migrate:rollback`, `migrate:fresh`, or database import as a routine undo step. Database restores can overwrite records created after the backup.

## Still required before a public launch

- Hosting, a real domain, HTTPS, and production environment settings are deferred at the owner's request. Keep the working local HTTP URL until a real deployment target exists.
- The local storage volume has approximately 3.3 GB free; the project's readiness check requires at least 5 GB. Provision sufficient persistent storage. No unrelated files were deleted to bypass this check.
- These verified backups are on the same computer. Set up a protected, preferably encrypted off-host backup destination, retention, and scheduled backups on the selected host. Test recovery there too.
- `system:operations` found one existing reservation notification needing attention. Review it before retrying; it was not resent during this work.
- AI daily and monthly request limits are not configured. Set shop-approved limits before relying on paid AI functionality; no spending limit was invented here.
- Real delivery to the configured feedback mailbox has not been tested.
- On the actual host, follow `DEPLOYMENT_CHECKLIST.md`, including worker supervision, scheduler checks, production smoke tests, and another backup/restore verification.

Useful read-only checks:

```powershell
php artisan migrate:status
php artisan system:readiness
php artisan system:operations
```

The readiness command is expected to remain blocked locally by environment, HTTPS, and disk capacity. The operations command currently reports the existing notification needing attention.
