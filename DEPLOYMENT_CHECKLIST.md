# FurniCraft real-host deployment checklist

Run this on the actual production host after configuring `.env` from `.env.production.example`. These commands assume Ubuntu/Debian Linux, systemd, cron, MySQL/MariaDB, a `database` queue, and a local private storage volume. Replace the example paths, account names, database names, and URL with the host's real values. Do not paste passwords or API keys into commands, shell history, or this file. Run Artisan as the application user.

The examples use `/srv/furnicraft/current` as a symlink to a release directory. Keep `.env` and private storage persistent across releases. Use the same Bash session for the commands so the variables remain set. Run the backup and rollback sections during a planned maintenance window.

## 1. Set non-secret host variables and check readiness

```bash
APP_DIR=/srv/furnicraft/current
APP_USER=www-data
PHP_BIN="$(command -v php)"
PRIVATE_ROOT=/var/lib/furnicraft/private
PUBLIC_URL=https://your-production-domain.example
cd "$APP_DIR"
sudo -u "$APP_USER" "$PHP_BIN" artisan system:readiness
curl -fsS --max-time 10 "$PUBLIC_URL/health"
```

Replace `PUBLIC_URL` with the real HTTPS URL and `PRIVATE_ROOT` with the configured `PRIVATE_STORAGE_ROOT`. **Expected:** every readiness line says `PASS`, followed by `Production configuration checks passed`; `/health` returns HTTP 200 with `"status":"ok"` and `database`, `cache`, and `queue` checks set to `true`. The health endpoint checks queue connectivity, not whether a worker is running.

## 2. Send a real test email

```bash
cd "$APP_DIR"
sudo -u "$APP_USER" "$PHP_BIN" artisan tinker --execute='\Illuminate\Support\Facades\Mail::raw("FurniCraft delivery check", function ($message) { $message->to(config("services.feedback.admin_email"))->subject("FurniCraft deployment mail check"); }); echo "MAIL_SEND_OK\n";'
```

**Expected:** `MAIL_SEND_OK` with no mail exception, and an email with subject `FurniCraft deployment mail check` arrives in the real `ADMIN_FEEDBACK_EMAIL` inbox. Check spam and the provider's delivery log if it does not arrive. The printed marker alone does not prove delivery.

## 3. Run and verify the queue worker under systemd

This worker's 540-second timeout is shorter than the configured `DB_QUEUE_RETRY_AFTER=600` seconds.

```bash
sudo tee /etc/systemd/system/furnicraft-queue.service >/dev/null <<EOF
[Unit]
Description=FurniCraft database queue worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$APP_USER
WorkingDirectory=$APP_DIR
ExecStart=$PHP_BIN artisan queue:work database --queue=ai,default --sleep=3 --tries=3 --timeout=540
Restart=always
RestartSec=5
TimeoutStopSec=600

[Install]
WantedBy=multi-user.target
EOF
sudo tee /etc/systemd/system/furnicraft-mail.service >/dev/null <<EOF
[Unit]
Description=FurniCraft mail queue worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$APP_USER
WorkingDirectory=$APP_DIR
ExecStart=$PHP_BIN artisan queue:work database --queue=mail --sleep=1 --tries=3 --timeout=60
Restart=always
RestartSec=5
TimeoutStopSec=90

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now furnicraft-mail
systemctl is-enabled furnicraft-mail
systemctl is-active furnicraft-mail
sudo systemctl enable --now furnicraft-queue
systemctl is-enabled furnicraft-queue
systemctl is-active furnicraft-queue
```

**Expected:** `systemctl is-enabled` prints `enabled`; `systemctl is-active` prints `active`. If the service fails, inspect `sudo journalctl -u furnicraft-queue -n 50 --no-pager` without copying private log content into tickets.

Dispatch a harmless job to prove that the worker actually consumes jobs. Clear any old canary first so a stale file cannot produce a false pass.

```bash
cd "$APP_DIR"
sudo -u "$APP_USER" "$PHP_BIN" artisan tinker --execute='\Illuminate\Support\Facades\Storage::disk("local")->delete("queue-canary.txt");'
sudo -u "$APP_USER" "$PHP_BIN" artisan tinker --execute='dispatch(function () { \Illuminate\Support\Facades\Storage::disk("local")->put("queue-canary.txt", "processed"); }); echo "QUEUE_CANARY_DISPATCHED\n";'
for attempt in 1 2 3 4 5 6; do
    sudo -u "$APP_USER" test -f "$PRIVATE_ROOT/queue-canary.txt" && break
    sleep 5
done
sudo -u "$APP_USER" test -f "$PRIVATE_ROOT/queue-canary.txt" && echo QUEUE_CANARY_PROCESSED
sudo -u "$APP_USER" "$PHP_BIN" artisan tinker --execute='\Illuminate\Support\Facades\Storage::disk("local")->delete("queue-canary.txt");'
```

**Expected:** `QUEUE_CANARY_DISPATCHED`, then `QUEUE_CANARY_PROCESSED` within 30 seconds. If the second marker is absent, inspect the worker service and `php artisan queue:failed`; do not treat `/health` as proof of processing.

## 4. Install and verify the scheduler cron entry

```bash
printf '* * * * * %s cd %s && %s artisan schedule:run >> /dev/null 2>&1\n' "$APP_USER" "$APP_DIR" "$PHP_BIN" | sudo tee /etc/cron.d/furnicraft >/dev/null
sudo chmod 0644 /etc/cron.d/furnicraft
sudo systemctl enable --now cron
systemctl is-active cron
sudo cat /etc/cron.d/furnicraft
cd "$APP_DIR"
sudo -u "$APP_USER" "$PHP_BIN" artisan schedule:list
```

**Expected:** `systemctl is-active` prints `active`; the cron file shows one `* * * * *` entry running `artisan schedule:run` as the application user; `schedule:list` shows `room-images:prune`, `feedback:prune`, `reservations:expire`, `reservations:remind`, `notifications:recover`, and `room-images:retry-deletions`. After the next minute, confirm that the host's cron log records the entry. Cron logging location varies by host; on Ubuntu/Debian check `sudo grep 'artisan schedule:run' /var/log/syslog | tail -n 5`. An installed entry without an active cron daemon is not sufficient.

## 5. Back up the database and private files together

Use a mounted, encrypted backup destination outside the web root. Supply a database account with backup privileges. `--password` prompts without placing the password on the command line. Keep the application in maintenance mode and stop its worker until both archives are complete, so database rows and private files describe the same point in time.

```bash
(
set -euo pipefail
DB_HOST=127.0.0.1
DB_NAME=furnicraft
DB_USER=furnicraft_backup
BACKUP_ROOT=/mnt/encrypted-backups/furnicraft
BACKUP_DIR="$BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ)"
mountpoint -q /mnt/encrypted-backups
umask 077
install -d -m 0700 "$BACKUP_DIR"
cd "$APP_DIR"
sudo -u "$APP_USER" "$PHP_BIN" artisan down
resume_application() {
    sudo systemctl start furnicraft-queue furnicraft-mail
    sudo -u "$APP_USER" "$PHP_BIN" artisan up
}
trap resume_application EXIT
sudo systemctl stop furnicraft-queue furnicraft-mail
mysqldump --host="$DB_HOST" --user="$DB_USER" --password --single-transaction --quick --routines --triggers --events "$DB_NAME" | gzip -1 > "$BACKUP_DIR/database.sql.gz"
sudo tar -C "$PRIVATE_ROOT" -czf - . > "$BACKUP_DIR/private-storage.tar.gz"
sudo find "$PRIVATE_ROOT" -type f | wc -l > "$BACKUP_DIR/private-file-count.txt"
gzip -t "$BACKUP_DIR/database.sql.gz"
tar -tzf "$BACKUP_DIR/private-storage.tar.gz" >/dev/null
(cd "$BACKUP_DIR" && sha256sum database.sql.gz private-storage.tar.gz private-file-count.txt > SHA256SUMS && sha256sum -c SHA256SUMS)
)
```

**Expected:** the encrypted backup mount is present; `artisan down` reports maintenance mode; both archives are nonempty; `gzip -t` and `tar -tzf` exit successfully; all three `sha256sum -c` lines end in `OK`; the worker returns to `active`; `artisan up` reports that the application is live. The `EXIT` trap restarts the worker and lifts maintenance mode if a backup command fails. Do not mark an incomplete archive as a valid backup. Copy the verified archives to a separate encrypted off-host destination using the host's backup system and confirm its retention and access controls.

## 6. Restore a copy in isolated staging

Use a separate, empty staging database and private directory. Copy the verified backup directory to staging through the approved secure channel. Never run these restore commands against production. The guards below stop if staging targets equal the production targets.

```bash
set -euo pipefail
BACKUP_DIR=/mnt/encrypted-backups/furnicraft/REPLACE_WITH_BACKUP_TIMESTAMP
PRODUCTION_DB_NAME=furnicraft
PRODUCTION_PRIVATE_ROOT=/var/lib/furnicraft/private
STAGING_DB_HOST=127.0.0.1
STAGING_DB_NAME=furnicraft_restore_test
STAGING_DB_USER=furnicraft_restore
STAGING_APP_USER=www-data
STAGING_PRIVATE_ROOT=/var/lib/furnicraft-restore-test/private
test "$STAGING_DB_NAME" != "$PRODUCTION_DB_NAME" && test "$STAGING_PRIVATE_ROOT" != "$PRODUCTION_PRIVATE_ROOT"
(cd "$BACKUP_DIR" && sha256sum -c SHA256SUMS)
install -d -m 0700 "$STAGING_PRIVATE_ROOT"
test -z "$(find "$STAGING_PRIVATE_ROOT" -mindepth 1 -print -quit)"
test "$(mysql --host="$STAGING_DB_HOST" --user="$STAGING_DB_USER" --password --skip-column-names "$STAGING_DB_NAME" -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();')" -eq 0
gzip -dc "$BACKUP_DIR/database.sql.gz" | mysql --host="$STAGING_DB_HOST" --user="$STAGING_DB_USER" --password "$STAGING_DB_NAME"
tar -xzf "$BACKUP_DIR/private-storage.tar.gz" -C "$STAGING_PRIVATE_ROOT"
sudo chown -R "$STAGING_APP_USER" "$STAGING_PRIVATE_ROOT"
mysql --host="$STAGING_DB_HOST" --user="$STAGING_DB_USER" --password --skip-column-names "$STAGING_DB_NAME" -e 'SELECT COUNT(*) FROM users;'
test "$(find "$STAGING_PRIVATE_ROOT" -type f | wc -l)" -eq "$(cat "$BACKUP_DIR/private-file-count.txt")" && echo PRIVATE_FILE_COUNT_MATCHES
```

**Expected:** all three checksums report `OK`; the staging targets are empty before import; the import and extraction exit successfully; the SQL query prints a nonnegative user count; `PRIVATE_FILE_COUNT_MATCHES` appears. Start the staging app with its own `.env` pointing to these restored resources, then run `php artisan migrate:status` and check staging `/health`, login, catalog, reservations, and access to a private room photo. Record the restore date, duration, and result. A successful archive command alone does not verify recovery.

## 7. Roll back application code if a release fails

Keep the previous release directory and record its path before deploying. Confirm that its code can use the current database schema; a code rollback does not reverse migrations or undo customer writes. Keep the same production `APP_KEY`, `.env`, and persistent private storage. If the schema is incompatible, plan a maintenance-window data restore from a verified backup instead of running a blind down migration.

```bash
set -euo pipefail
CURRENT_LINK=/srv/furnicraft/current
PREVIOUS_RELEASE=/srv/furnicraft/releases/REPLACE_WITH_PREVIOUS_RELEASE
test -L "$CURRENT_LINK" && test -d "$PREVIOUS_RELEASE"
sudo -u "$APP_USER" "$PHP_BIN" "$CURRENT_LINK/artisan" down
sudo systemctl stop furnicraft-queue furnicraft-mail
ln -sfnT "$PREVIOUS_RELEASE" "${CURRENT_LINK}.rollback"
mv -Tf "${CURRENT_LINK}.rollback" "$CURRENT_LINK"
cd "$CURRENT_LINK"
sudo -u "$APP_USER" "$PHP_BIN" artisan optimize:clear
sudo -u "$APP_USER" "$PHP_BIN" artisan config:cache
sudo -u "$APP_USER" "$PHP_BIN" artisan route:cache
sudo -u "$APP_USER" "$PHP_BIN" artisan view:cache
sudo systemctl start furnicraft-queue furnicraft-mail
sudo -u "$APP_USER" "$PHP_BIN" artisan up
readlink -f "$CURRENT_LINK"
systemctl is-active furnicraft-queue
curl -fsS --max-time 10 "$PUBLIC_URL/health"
```

**Expected:** `readlink -f` prints the selected previous release path; the worker is `active`; `/health` returns HTTP 200 and `"status":"ok"`. Repeat the login, catalog, reservation, and private-photo smoke checks. If the rollback fails before `artisan up`, keep maintenance mode on, diagnose using host logs, and do not reopen traffic until the checks pass.

Pause the scheduler/cron during backup and rollback maintenance and restore it after consistency checks. Verify a real queued test email through the dedicated mail worker as well as the default-queue canary. See [beta-hardening.md](docs/beta-hardening.md). Local tests do not establish these host checks.
