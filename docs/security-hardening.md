# Security hardening — 5 November 2026

This records application changes and local verification. It is not a penetration-test certification or confirmation that a future production host is secure.

## Account security

- Changing an email address requires the current password. Name and phone updates do not. The password is never rendered back into the form or included in Laravel's old input.
- The controller rechecks the current password against a locked, fresh user record before changing email. Email changes invalidate outstanding reset codes, rotate the remember token, revoke database sessions, deactivate the account, and require verification of the new address.
- Email verification locks the user and latest code in one transaction. Activation and code consumption commit together. Expired, consumed, and superseded codes cannot activate an account. Six incorrect attempts consume a code regardless of the requesting IP.
- Verification-code issuance and its 60-second resend cooldown use the same user row lock to prevent competing issuances from leaving multiple usable codes.
- Password-reset issuance rechecks account state and cooldown while holding the user lock. Public reset responses and session-based resend countdowns no longer differ according to whether the account exists. Internal delivery failures remain recorded, without SMTP details in the public response. These changes do not establish constant-time responses for every network/database condition.
- Existing reset-grant expiry, replay prevention, session revocation, active-account checks, password hashing, and CSRF protection remain in place.

## Additional per-user request limits

Attempts count even when validation fails. Changing IP does not reset these authenticated-user counters; each named action has its own counter.

| Action | Limit |
| --- | --- |
| Profile updates | 10 per minute |
| Password changes | 5 per 15 minutes |
| Account deletion attempts | 3 per 15 minutes |
| Reservation creation | 5 per minute |
| Reservation cancellation | 10 per minute |

Existing login, registration, reset, catalog search, recommendation, chatbot, room upload, and AI-preview throttles are retained. Production deployments need a shared limiter cache and correct trusted-proxy configuration. Edge-level abuse protection remains a hosting concern.

## Browser protections

Global responses now include MIME sniffing protection, frame protection, a referrer policy, and a permissions policy. CSP limits scripts to the same origin or a fresh per-request nonce, blocks inline event-handler attributes and embedded objects, and restricts form actions and framing. Laravel Vite tags and the existing theme/countdown scripts receive the nonce. Confirmation handlers and the product-image fallback use external JavaScript so they continue to work under this policy.

Inline styles remain allowed because existing layouts and photo-preview positioning use them. Images allow same-origin, data, and blob sources. A local Vite hot-server origin is permitted only in the local environment when the configured hot file exists. HSTS is sent only on secure production requests; local HTTP access is preserved.

## Permissions and row access

Customer ownership is enforced by Laravel relationship queries and controller checks. Permanent regression tests cover foreign room photos, analysis status, room deletion/reanalysis, recommendations, reservation cancellation/listing, admin pages, and attempted role injection through registration/profile forms.

This is application-level row authorization, not database-native RLS. The runtime database account is restricted to application-schema DML but can access rows across customers; it is not a separate database identity for every customer. Keep the database inaccessible to clients and preserve ownership checks on every new endpoint. No database migration or platform switch was introduced for this work.

## Verification

- Full PHP suite: **219 tests passed, 1,894 assertions** on an isolated SQLite database.
- The previous stale UI assertions were updated to the requested current layout/logout behavior; core workflow assertions remain. The verification-notification fixture now starts with an unverified, inactive account. The production template test accepts a valid configured feedback recipient while continuing to check empty secret placeholders and HTTPS cookie settings.
- Desktop and mobile browser checks passed for login, profile save, password-required email-change rejection, password dialogs, image fallback, and the nonce-enabled reset countdown.
- Browser checks confirmed that room deletion and admin deactivation still display their confirmation prompts and can be cancelled. The theme script ran with its nonce; an injected inline script without a nonce was blocked by CSP.
- JavaScript syntax and scoped whitespace checks passed.
- The actual local login endpoint returns HTTP 200 and the new CSP, frame, and MIME protection headers.

Transaction rollback and code reuse are covered in tests. SQLite does not exercise MySQL row-lock contention; this is not a concurrent-load certification for the eventual production database.

## Remaining deployment work

The owner deferred hosting and domain selection. Configure and verify the real HTTPS origin, secure cookies, session encryption, trusted proxies if applicable, worker supervision, shared cache, and database network access on that host. Provision adequate disk space and protected off-host backups. Review the existing pending reservation notification before retrying it. Real mailbox delivery and shop-approved AI spending/request limits remain operational checks. Do not describe these deferred items as completed based on a green local test suite.
