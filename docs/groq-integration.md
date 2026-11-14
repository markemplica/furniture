# Groq beta integration — 5 November 2026

**Inactive:** the owner requested a return to Gemini on 5 November 2026. Actual configuration and environment templates now select `AI_PROVIDER=gemini`. This document records the optional Groq integration, not the active provider. Existing beta expiry and spending safeguards remain in force. Groq-specific photo consent does not authorize a Google transfer.

## Active configuration

`AI_PROVIDER=groq` routes chatbot answers, room-photo analysis, and visual placement checks exclusively to Groq. There is no automatic Gemini fallback. Gemini credentials are retained privately for an explicit rollback, never sent to Groq. Recommendations and summaries remain local.

- Chat: `openai/gpt-oss-20b`, low reasoning, one actual chat attempt, 1,024 completion-token ceiling.
- Room understanding: `qwen/qwen3.8-27b`, non-thinking mode, at most 4,096 completion tokens. This is a preview model and may change availability.
- Private photos remain inline base64 data URLs, never publicly uploaded for the provider. Existing preparation reduces image dimensions. Three images maximum, combined encoded image content limited to 3 MB before sending.
- JSON schema best-effort mode preserves existing optional geometry fields. Invalid geometry still falls back locally; schema validation is not proof of physical fit.
- Generated furnished images are disabled with Groq. Local 2D composites remain available; they are planning estimates, not verified measurements.

The Groq key was absent when the integration was configured. Add a valid key privately to `.env`:

```dotenv
AI_PROVIDER=groq
GROQ_API_KEY=your_private_key
GROQ_CHAT_MODEL=openai/gpt-oss-20b
GROQ_VISION_MODEL=qwen/qwen3.8-27b
```

Reload Laravel configuration if cached and restart/relaunch the existing workers after changing settings. Never put keys in browser code or commit `.env`. A missing Groq key produces local fallback even when a Gemini key exists. No live Groq quality, account quota, or Free-tier status has been verified yet.

## Shared safeguards

The existing `GEMINI_*` budget environment names remain **shared AI spending controls** for compatibility with deployed jobs. They apply to Groq too; the prefix does not select Google transport.

- Fixed beta expiry: `2026-11-19T18:09:36Z` (20 November, 02:09:36 Philippine time).
- USD 1/day, USD 15/month and USD 15 total beta reservation allowance; durable total never resets automatically.
- 100 provider attempts/day, 1,500/month, plus existing shared project/user minute and daily limits.
- Conservative reserves: USD .01/chat attempt and USD .10/analysis or visual-check attempt, even on a Free-tier account. These are app allowances, not provider invoice measurements.
- Text transport at most one selected transient retry; at most three sends including schema recovery per operation. Chat has one send. No retry on 401/403/429 or ambiguous network timeouts. Quota/authentication failures open the selected key's circuit.
- Provider output and thinking-token metadata recorded. Only the chosen provider key is attached, with redirects disabled and a fixed endpoint.
- Dashboard/log/email alerts and bounded client polling remain active. Switching provider does not erase the usage ledger or beta reservation.

Groq's Free plan and available account limits must be checked in its Console. Selecting Groq in this app does not select or guarantee a free billing plan. If the account is upgraded, the same API key may incur charges under that plan. Do not automatically switch to paid Gemini when a free quota is exhausted.

## Consent and existing data

New Groq uploads require a provider-specific hidden form value and `room-ai-groq-v1` consent version. Stale forms displaying Google consent cannot grant Groq consent. Old `room-ai-v1` photo consent does not authorize transmission to Groq: upload the photo again after reviewing the new consent label. Existing photos, history, and local previews are preserved.

The database's historical `gemini_used` and `gemini_consent_*` columns and internal `GeminiClient`/`GeminiService` class names remain for compatibility; they are not evidence that a request went to Google. New analysis results add `ai_provider=groq`, chat stores `ai_source=groq`, and privacy audit events identify Groq. Historical Gemini audit/chat entries keep their original labels. Analysis, chat and verification caches distinguish the selected provider. No schema migration is required.

## Rollback

Set `AI_PROVIDER=gemini`, reload configuration and restart workers to explicitly select the previous provider, or set `GEMINI_ENABLED=false` to stop all new external AI requests. Do not remove usage rows or change the beta deadline to bypass a limit. Groq failures never trigger this rollback automatically.

## Verification

- Full PHP regression suite: **251 tests, 2,044 assertions passed** with isolated test data and mocked provider responses.
- Groq tests exercise real transport payload conversion, bearer-only credentials, token accounting, room-analysis JSON and geometry normalization, missing-key local fallback, bounded retries, quota circuits, incomplete-response rejection, beta expiry, image-generation blocking, old-provider photo consent rejection, Groq disclosures, stale form rejection and model allowlisting.
- JavaScript syntax check passed. Actual configuration bootstrap confirmed Groq selected, the intended chat/vision models, unchanged beta expiry and USD 15 total allowance, image generation off, no queued jobs and no Groq key. Both workers were restarted.
- No live model-quality, Free-tier eligibility, account quota, or provider delivery test has been performed; adding the private Groq key is still required.

## Sources checked

- [Groq vision API and private inline images](https://console.groq.com/docs/vision)
- [Groq structured output modes](https://console.groq.com/docs/structured-outputs)
- [Qwen vision model and non-thinking mode](https://console.groq.com/docs/model/qwen/qwen3.8-27b)
- [Groq model status and pricing](https://console.groq.com/docs/models)
- [Organization and token rate limits](https://console.groq.com/docs/rate-limits)

Paid-plan prices listed when checked: GPT-OSS 20B USD .075/million input and .30/million output tokens; Qwen 3.8 27B USD .80/million input and 4/million output. Therefore Groq vision is not automatically cheaper than the previous Gemini Flash-Lite paid rates. Free-plan savings depend on the actual account, model availability, token volume, and quotas. Reconcile provider billing separately from the app reservation ledger.
