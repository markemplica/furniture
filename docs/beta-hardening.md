# Beta security and reliability update

This is a local implementation update, not production certification. Existing business data and the developer's `.env` are not changed by the review or tests.

## Apply and start

1. Save a snapshot of the current working tree, including untracked files. Back up the database, persistent private files, and deployment secrets using the existing approved process before applying migrations. Pause incoming writes, the scheduler, and all old workers for the update.
2. Apply the three additive `2026_11_01` migrations with `php artisan migrate` (use `--force` only on the approved production host). They add notification dispatch state, pending file deletions, and AI usage counters. Do not start updated web/worker code against the old schema.
3. Run `php artisan config:clear` after reviewing configuration changes. Do not copy example credentials over an existing `.env`.
4. Start two independent database workers:

   ```sh
   php artisan queue:work database --queue=mail --tries=3 --timeout=60
   php artisan queue:work database --queue=ai,default --tries=3 --timeout=540
   ```

   On Windows, `start-system.ps1` starts each worker separately. Stop obsolete default-only workers yourself before switching; the launcher does not terminate existing processes. `composer dev` also starts separate workers. Keep `DB_QUEUE_RETRY_AFTER=600`. Restart both workers after releases. On Linux supervise both services in `DEPLOYMENT_CHECKLIST.md`.
5. Restore the scheduler (`php artisan schedule:work` locally, or the host's every-minute scheduler). It now runs `notifications:recover` every minute and `room-images:retry-deletions` every five minutes, in addition to retention and reservation tasks.
6. Verify account access, a reset using two browsers, queued mail consumption while AI jobs wait, private-photo access, and deletion. Use isolated data and fake mail until explicitly approved for a real host test.

## Account recovery

Reset authorization identifies the exact code record, account, and expiry. Issuance, consumption, and profile password changes serialize through the account row. A successful reset consumes all outstanding codes, rotates the remember token, and removes the account's database sessions. A profile password change preserves the current session while revoking others and invalidating outstanding reset codes.

Laravel's session authentication middleware rejects stale password hashes with other session drivers. Previously issued non-database sessions without a password hash cannot be retroactively checked: invalidate legacy sessions when deploying this middleware to such a host. The current configured database-session deployment explicitly deletes session rows on password changes.

## Notification recovery and delivery meaning

Reservation logs are durable outbox records. A failed enqueue leaves the record recoverable; a 15-minute dispatch lease prevents repeated recovery from continuously enqueuing the same mail. Recovery stops after eight successful dispatch reservations. Inspect exhausted/old records before manually resetting their attempts. Existing status-level deduplication remains unchanged, including suppression of a second approval email after reapproval.

The standard database queue must use the same database connection as application records for atomic enqueue/lease persistence. Other backends retain recovery, but cannot share that transaction. Per-log queue locks prevent simultaneous sends; notifications skip records already marked sent. A worker crash after the mail provider accepts a message but before the sent log commits can still produce a duplicate on retry. SMTP cannot guarantee exactly-once inbox delivery without provider support.

`sent` means the configured mail transport accepted the send; it does not prove inbox delivery. Check provider delivery/bounce evidence on the real host. The existing visit reminder remains a synchronous scheduler email; it does not share the AI worker.

## Photo cleanup

Deletion intents commit with database changes and survive a process crash. Successful deletion removes its intent; failed deletion retries with increasing delay, up to an hour. Retry only recorded intents; the storage audit continues to report orphan files without automatically deleting them. A referenced private file is preserved if a path was reused. Keep the private volume persistent and check storage audit output before manual cleanup.

Image headers are checked against the configured pixel budget before GD orientation decoding. The existing format, byte-size, metadata stripping, and final prepared-image size checks remain in effect.

## Aggregate AI request limits

Set `GEMINI_DAILY_REQUEST_LIMIT` and/or `GEMINI_MONTHLY_REQUEST_LIMIT` to shop-approved attempted-call limits. Empty means unconfigured, and `0` blocks all provider calls. Counters cover all users, workers, model fallbacks, and HTTP retries in this installation, using UTC day/month boundaries. Calls made outside this app are not counted. Existing eight-call preview limits remain separate.

Counters are conservative reservations made before sending, so an interrupted send may consume a count without provider billing. They are not token, currency, or invoice measurements. Existing telemetry records model, latency, outcome and fallback; use provider billing/quota controls for monetary exposure. Configure and test alerts before enabling paid AI. Aggregate rejection retains local chat/room fallbacks.

The configured/template retention default remains 90 days. Existing explicit values, including the current local 30-day setting, are preserved; this update does not change `.env`.

## Monitor and verify

Run `php artisan system:operations` from the host monitor. A nonzero result flags mail older than two minutes, overdue/exhausted reservation dispatch, or overdue/repeated file deletion failures. Review failures before retrying. These starting alert thresholds are operational settings to tune with actual beta measurements, not performance guarantees. AI counters and configured caps are reported without prompts, photos, customer identities, or credentials. Monitor provider spend separately.

The monitor needs an external operator/host integration to deliver alerts. Also monitor worker process restarts, scheduler execution, failed jobs, disk capacity, backup success and external HTTPS availability. `/health` proves connectivity, not worker/scheduler progress or backup validity.

Use isolated databases/storage and fake mail/HTTP for automated tests. SQLite tests do not prove MySQL lock contention. Before launch run simultaneous reset and reservation requests on isolated MySQL, verify real queued email, validate representative photo placement against measured references, and record a successful off-site backup restore with recovery time/data loss.

## Rollback

Stop updated workers and pause the scheduler before reverting to the saved code snapshot. Leave the additive schema in place for a code rollback; old code ignores it. Do not remove pending deletion/outbox records or reset usage counters to get around a failure. Inspect queued payload compatibility before processing jobs with older code. Revoke active sessions if returning to the old account-recovery implementation; that rollback restores the security weaknesses.

Drop the new schema only during an approved maintenance procedure after backups and after resolving/preserving pending intents. Never use `migrate:fresh` or a blind multi-step rollback on existing business data. A tested restore, supervised workers, real delivery evidence, and an alert owner are still release prerequisites.
