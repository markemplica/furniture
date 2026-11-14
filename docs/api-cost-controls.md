# API usage and cost controls — 5 November 2026

**Current provider:** the owner requested a return to **Gemini** on 5 November 2026. Chatbot, room analysis and visual placement checks select `gemini-3.1-flash-lite`. Groq is inactive; its optional adapter remains available for an explicit future switch. The spending ledger, 15-day deadline, USD 1/day and USD 15 total beta allowance remain unchanged. Image generation stays disabled. Photo consent explicitly granted for Groq is not permission to send the photo to Google.

## Current capstone configuration

The owner shortened the pilot to **15 days** after initially approving USD 30/month. The active local configuration enables guarded Gemini text/image-understanding calls until **20 November 2026, 02:09:36 Asia/Singapore (19 November 18:09:36 UTC)**, with **USD 1/day and USD 15 total beta reserved allowance**, and ceilings of 100 provider attempts/day and 1,500/month. The monthly allowance is also lowered to USD 15. The first applicable limit stops new attempts and preserves local fallbacks. **Paid image generation remains disabled.** The key remains private on the server. Catalog browsing, local recommendations, local chat guidance, and approximate local previews remain available. These application controls do not cancel historical charges, requests already in flight, or another application's use of the same Google project/key. Revenue is unknown; no profit-based guarantee is possible.

The migration was applied after a verified backup and isolated restore: `storage/app/ops-backups/20261105-010655` (31 pre-migration tables and 396 private files). The restricted runtime database account remains in use. The schema change used the separate administrative connection; runtime DDL privileges were not added.

## Outbound request inventory

| Path | External work | Controls and reductions |
| --- | --- | --- |
| Recommendation ranking and summaries | None | Both use local catalog logic. Removed the obsolete provider-backed summary path. |
| Catalog search and product pages | None | Local database queries; search has its existing 60/minute limit. |
| Recommendation chatbot | Gemini only for questions not answered locally | Local catalog/price/material/fit answers; bounded context; per-user cache; concurrent identical questions share a lock. |
| Room upload with consent | Optional queued Gemini analysis | Explicit upload action; consent required; existing photo/hash caches; global and per-user budgets. |
| Room re-analysis | Optional queued Gemini analysis | Explicit POST; shared AI-action limits; bounded job; no automatic page-view analysis. |
| Visual placement checking | Optional Gemini image understanding | Explicit action; identical checks cached; concurrent checks share a lock; client loop remains limited to three checks. |
| Furnished image enhancement | Optional Gemini image generation and verification | Explicit action; feature separately disabled by default; one 1K image candidate, one image attempt, no image model escalation. |
| Room/status polling | None | Read-only backend responses; bounded sequential polling with timeouts; stop on access or rate-limit failures. |
| Verification/reset/reservation/feedback/alerts | SMTP notification queue, not Gemini | Existing finite mail attempts and notification recovery; new shared limits on guest email-trigger endpoints; alert email channel separately capped. |
| Production password breach check | Laravel password-validation service | Separate from Gemini billing; public password flows have request throttles. |

Application `Http::post` calls to Gemini are centralized in `GeminiClient`. No direct browser-side Gemini transport or browser API-key configuration was found in the reviewed source. Storage is configured locally; object-storage and hosting costs are separate deployment concerns.

## Spending gates

Every permitted provider transport attempt reserves usage **before** sending, including retries. A database transaction locks the relevant project and user counters in a consistent order. Missing/zero limits, disabled AI, ledger/cache failure, and an open circuit stop the call instead of sending it untracked.

| Gate | Active local setting |
| --- | --- |
| Paid AI switch | On; paid image generation off |
| Project daily/monthly requests | 100 / 1,500 |
| Project daily/monthly reserved USD allowance | 1 / 15 |
| Total beta reserved USD allowance | 15, durable `beta:total` bucket; does not reset at a month boundary |
| Beta deadline | 2026-11-19T18:09:36Z; checked before every transport reservation |
| Project provider attempts/minute | 10 |
| User provider attempts/minute | 5 |
| User provider attempts/day and month | 20 / 200 |
| Reserved allowance per chat attempt | USD 0.01 |
| Reserved allowance per analysis/verification attempt | USD 0.10 |
| Reserved allowance per image-generation attempt | USD 0.25 |

These deliberately conservative reservations **are not measured invoice costs**. They are not automatically refunded after an error or timeout, because the provider may already have processed/billed the request. Previous untracked charges are not reconstructed. Dates reset in UTC, which can differ from provider quota/billing boundaries. Lowering a budget below its existing reservation total blocks further calls; clearing caches does not erase the durable spending ledger.

Do not delete/reset current usage rows to make an alert disappear. That would remove a spending safeguard. Routine database backups include the ledger and alerts.

`GEMINI_BETA_EXPIRES_AT` is a fixed UTC instant, not a rolling duration calculated during boot. When configured, a positive `GEMINI_BETA_BUDGET_USD` is required; invalid dates or nonpositive beta allowances block paid calls. Long-running workers recheck the deadline on every attempt, including retries. An already-sent request may finish after the deadline. The admin dashboard shows the deadline and total beta reservation. Extending the deadline does not erase prior beta spending.

## Retry, model, and loop policy

- Production text allowlist: `gemini-3.1-flash-lite`; image allowlist: `gemini-3.1-flash-lite-image`. Higher-priced model IDs are rejected before sending. Automatic fallback model settings are blank. Model availability/quality has not been tested with paid live calls during this work.
- Text transport permits at most one retry, only for selected transient HTTP statuses; a whole text operation has a maximum of three provider attempts including explicitly permitted schema recovery. Caller-supplied extreme retry/model counts cannot bypass the caps. Actual chat requests use one attempt.
- A JSON-schema-specific 400 can trigger one JSON-mode recovery. Other 400s, 401/403, and 429 are not blindly retried or routed to another model.
- Image generation makes one attempt. Network timeouts are not repeated because processing/billing may already have occurred. Redirects are disabled; only the fixed Google endpoint is used.
- A shared circuit pauses calls after quota/credential/network errors or a burst of failures. Cache failure also fails closed. Clearing a circuit does not clear request or money counters.
- One candidate per response; chat output cap 384 tokens, structured analysis/verification cap 4,096. No grounding, search, executable tools, URL-context tools, or agent tool loops are configured.
- Text/schema/system content is limited to 24,000 bytes. At most three inline JPEG/PNG/WebP images are accepted, with byte and pixel/dimension checks. Unsupported parts are rejected before sending.
- Provider-bound room, catalog-reference, and verification images reuse the 1,280-pixel preparation path; original private uploads are preserved. This reduces image input size instead of repeatedly sending full-resolution originals.
- Fixed the visual-verification route's numeric-ID/slug mismatch and bound the product explicitly by ID. Generated URLs and the regression test now exercise the real endpoint; ownership checks still reject another customer's room. Identical visual checks reuse a cached result.
- Analysis jobs have a fixed dispatch deadline, a per-room work lock, and one provider-bearing queue attempt. Duplicate terminal jobs do no further work. The old moving `now()+10 minutes` retry deadline was removed. V2 terminal jobs are also skipped.
- Status polling never overlaps its previous batch, pauses while the document is hidden, and stops after 120 batches or ten minutes. Poll fetches time out and stop on 401/403/404/429. The optional enhancement poll already has its own total deadline; POST/status/check requests now have browser timeouts too.

## Endpoint abuse protection

| Endpoint group | Relevant controls |
| --- | --- |
| Chat | Login/active customer/ownership; 20/minute and 100/day chat-message limits; central project/user provider gates; local answers and duplicate-answer caching. |
| Upload | Login/ownership/consent; 5 uploads per ten minutes; JPG/PNG/WebP and 8 MB validation; provider payload checks and spending gates. |
| Re-analysis, visual verification, V2 POST | Shared 5/minute and 10/day per-user AI-action limits; ownership/consent; central provider gates. |
| Analysis/preview status | Ownership; 120/minute shared per-user status limiter. V2 status retains its existing separate throttle. No provider calls. |
| Registration, verification resend, forgot/reset resend, feedback | Existing per-IP throttles plus shared project limits of 30 requests/minute and 300/day across IPs. |
| Health | 30/minute per-IP; no model calls. |

Application throttling is not DDoS protection. Configure correct trusted proxies, shared cache, request-body limits, database network controls, and edge protection on the eventual host. The global AI ledger is still enforced if an attacker registers multiple accounts.

## Alerts

The admin dashboard shows paid-AI status, attempted requests, reserved allowance, configured limits, and recent alerts. Logs and durable alert records are generated at 80% of project limits, when limits block a request, for provider quota/credential/circuit events, and after repeated provider failures.

The owner authorized emails to `lancefurniture15@gmail.com`. Each alert type is deduplicated per UTC hour; **at most one alert email total is queued per UTC hour**, with one delivery attempt. Other events remain visible in the dashboard/logs. Emails contain aggregate control information, not prompts, API keys, or room photos. A working mail worker and SMTP service are required. Log-write failures no longer suppress database/email alerts or recursively throw through the logger. Email routing/deduplication is tested with notification fakes. One authorized zero-budget setup alert was also queued on the actual SMTP configuration; the mail worker completed the job with no new failed jobs. Inbox/spam arrival has not been independently confirmed.

## Illustrative monthly cost at different activity levels

Published standard prices checked on 5 November 2026: Flash-Lite text/image input USD 0.25/million tokens; text/thinking output USD 1.50/million; Lite Image 1K output USD 0.0336/image. [Google pricing](https://aistudio.google.com/docs/pricing)

Assume each active AI user makes 10 uncached chats (2,000 input/384 billed output tokens each) and two analyses (6,000/2,048 each). The enhanced scenario adds one 1K image (6,000 input plus 1,024 text/thinking output tokens) and one verification (8,000/512). These are planning assumptions, not measurements.

| Active AI users/month | Basic AI, USD | With one enhancement/user, USD | Basic PHP at illustrative 60/USD | Enhanced PHP at illustrative 60/USD |
| --- | ---: | ---: | ---: | ---: |
| 100 | 1.99 | 5.93 | 119 | 356 |
| 1,000 | 19.90 | 59.31 | 1,194 | 3,558 |
| 10,000 | 199.04 | 593.08 | 11,942 | 35,585 |

No free-tier discount is assumed. Different token volumes, thinking, repeated checks, retries, pricing changes, taxes, FX, and payment fees change the result. A threefold activity/error stress scenario is approximately three times these figures. Hosting, storage, SMTP, historical charges, and other applications are excluded. PHP 60/USD is an explicit planning assumption, not a live exchange-rate quote.

Formula: `text USD = input_tokens × input_rate / 1,000,000 + billed_output_tokens × output_rate / 1,000,000`; add image charges separately. New response metadata records prompt, candidate, and thinking token counts to calibrate future estimates.

Reserved allowances intentionally stop usage earlier than these example invoice estimates: the basic mix reserves USD 0.30/user; one enhancement plus verification brings this to USD 0.65/user. Do not promise a number of AI users from a dollar budget without accounting for those reservations.

## Remaining provider setup and pilot checks

1. The active 15-day pilot allowance is USD 15 total and USD 1/day. No revenue/profit metric exists here to enforce a bill-to-profit ratio automatically. This is a conservative application reservation cap, not a measured provider invoice cap.
2. Active local request caps are 100/day and 1,500/month. Keep the approved model allowlists. Image generation stays off unless separately enabled for a budgeted pilot. Example environment files intentionally retain disabled/zero defaults and blank optional beta deadlines.
3. Configure a dedicated/restricted provider project/key. Review any auto-reload settings and all other users of that billing account. Application code cannot control spending outside this application.
4. Set a project spend cap and billing notifications in Google AI Studio. Google's documented project cap is experimental and can have approximately ten minutes of overage latency; it is a second layer, not an exact invoice guarantee. [Provider billing controls](https://aistudio.google.com/docs/billing#project-spend-caps). Provider request quotas are project-level, not per key. [Rate-limit documentation](https://aistudio.google.com/docs/rate-limits).
5. Run a small explicitly budgeted image-quality pilot, verify real alert delivery, and reconcile token logs/reservations with provider billing before increasing usage. Recheck pricing when changing model IDs or after published price changes.
6. To stop new app calls immediately, set `GEMINI_ENABLED=false`, clear/rebuild cached configuration as appropriate, and restart supervised workers. Do not rotate/delete the app encryption key or erase usage records.

Rollback of this budget-only change: set `GEMINI_ENABLED=false`, `GEMINI_DAILY_REQUEST_LIMIT=0`, `GEMINI_MONTHLY_REQUEST_LIMIT=0`, `GEMINI_DAILY_BUDGET_USD=0`, and `GEMINI_MONTHLY_BUDGET_USD=0`; retain `GEMINI_IMAGE_ENABLED=false`. Reload configuration/workers. No credentials or usage records need changing. A new full `.env` backup in the restricted earlier backup directory could not be created because that directory denies writes; these are the exact previous non-secret settings.

## Verification evidence

- Full PHP suite after beta expiry changes: 241 tests / 2,005 assertions passed. The earlier preview JavaScript suite had 40 tests passed; no JavaScript changed for beta expiry.
- New mocked-provider tests cover the precise expiry boundary for existing client instances and direct budget reservations, invalid beta configuration, and durable total allowance across a month change and cache clear.
- Pure-ledger MySQL concurrency test used a separate empty schema: 12 competing reservations at a limit of five yielded exactly five allowed and seven blocked; no errors, no real provider calls. The temporary schema was removed.
- Browser checks: local recommendations still work in zero-paid-call mode; admin budget controls render at desktop and mobile widths; pending polls do not overlap and stop at the deadline; polling stops after 429.
- Actual local health, login, and catalog endpoints returned HTTP 200. Workers were reloaded with paid AI disabled and both completed harmless file-only checks. The queue was empty afterward; the setup alert also completed without creating a failed job.
- Unit/integration checks cover disabled/missing limits, money reservation, failed-call retention, capped retries, quota circuit recovery, per-user quotas, approved models, prompt/output bounds, token metadata, alerts, fixed job deadlines, read-only GET/status routes, local/cached chat, duplicate jobs, and global email-trigger throttling.

After the 15-day beta change, a fresh Laravel bootstrap confirmed the fixed expiry above, USD 1/day, USD 15/month and USD 15 total beta allowances, 100/day and 1,500/month request caps, image generation disabled, zero beta reservations and no queued jobs. Workers were reloaded. No paid provider test was performed. The earlier zero-paid-call checks above describe verification before budget approval.

This is a tested set of application safeguards, not a guarantee about Google's final invoice, future pricing, stolen keys, other services, or an unconfigured production host. Google-side billing caps have not been configured by this change.

The fresh 5 November storage check reported **22.84 GB free**, above the existing five-GB readiness threshold. No unrelated files were deleted.
