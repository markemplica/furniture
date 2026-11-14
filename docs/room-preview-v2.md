# Room Preview v2 backend

The primary room preview automatically places the selected catalog image in 2D using the uploaded photo's detected floor or opening. It appears without waiting for a generated image or a visual-verification request. This follows the study's room-visualization scope in `funicraft-revised2.11.1.docx`: 2D furniture images, with no 3D, 360-degree rotation, or AR. Previously saved legacy room measurements remain preference/ranking information; customers do not need to enter measurements, and the visual placement uses photo geometry.

The v2 generated-image enhancement remains optional and disabled by default. It runs only after the customer explicitly presses the existing AI preview retry control. When v2 is disabled, that explicit control can request the existing visual check. A failed enhancement does not silently start another chain of AI requests. Photo analysis still requires explicit consent and a working queue worker.

If no safe placement is found, the preview displays the original room, explains the issue, and disables preview download. It does not force the product over a blocked area. Clear photos should show the floor and nearby walls; door previews require the full doorway frame. The user is never required to drag furniture or supply room dimensions.

## Enable for a staged rollout

Set these values in the local or deployment environment, then clear cached config:

```dotenv
ROOM_PREVIEW_V2_ENABLED=true
ROOM_PREVIEW_V2_ROLLOUT_PERCENT=5
ROOM_PREVIEW_PIPELINE_VERSION=v2
ROOM_IMAGE_RETENTION_DAYS=30
```

Run `php artisan migrate`, clear cached configuration with `php artisan config:clear`, and start a queue worker (`php artisan queue:work --queue=ai,default --tries=2 --timeout=540`). Start at 0-5%, inspect queue and AI telemetry, then increase rollout gradually. To stop immediately set `ROOM_PREVIEW_V2_ENABLED=false` and clear config cache.

The authenticated POST endpoint accepts `furniture_id` and an optional `retry` boolean: `/room/{roomImage}/preview-v2`. `retry` requests a fresh attempt only when the cached result is a fallback. It returns HTTP 202 with a status URL, or the cached completed/fallback result. Poll the status URL; rendered results include a short-lived signed artifact URL. The request never accepts prompts, client image bytes, product measurements, or placement coordinates. Owner checks, active catalog state, complete product dimensions, and the 10-per-hour route limit are enforced server-side.

## Runtime requirements and behavior

GD is required for image downscaling, image-quality sampling, deterministic PNG compositing, and pixel comparison. EXIF rotation is applied to JPEG uploads when GD and EXIF support are available. Generated image metadata is stripped. Without GD, v2 reports a placement-only rough estimate and does not pretend that a rendered preview is ready. Keep v2 disabled until GD is enabled and verified in the PHP runtime used by both web and queue workers.

AI processing requires explicit stored photo consent. Completed broad room analysis is reused from the uploaded room record and shared cache. The shared cache is keyed by the stored photo SHA-256 and pipeline version, protected by a cache lock; failed v2 analysis has a one-minute cooldown. Per-preview cache keys include product ID, product update time, and the placement implementation version, so older renders are not mistaken for current results.

The optional image generator receives server-calculated image coordinates and the actual 2D composite bounds. Its output must pass the five-score verifier and the outside-placement pixel guard. A catalog furniture set counts as one product with all of its photographed pieces. Rejected output retains a clearly labeled 2D estimate. The default is one generation attempt; the provider budget remains eight calls. FurniCraft budget exhaustion and a Google quota response have separate failure codes. Cancelled or superseded jobs cannot save an artifact over a newer request.

Server compositing applies validated opening quadrilaterals, 2D rotation/perspective/flip, and transparent-border cropping while retaining the source product's appearance. It never guesses that a white or opaque product region is background. Catalog photographs with opaque backgrounds therefore still need genuine cutouts. These transforms do not recover hidden product surfaces or guarantee physical dimensions from a single photograph. Verify actual measurements before purchase or installation.

Room images and generated artifacts are private. The normal retention cleanup removes room artifacts and stale shared analysis cache rows; the configured default is 90 days. API keys are sent in the Gemini authentication header, not in query strings. Never put customer photos or API keys in benchmark fixtures or logs.

## Benchmark

Regression coverage includes server placement propagation, shared analysis/cooldown, cancelled jobs, safe floor search, opening perspective, product aspect ratio, and artifact bounds. JavaScript tests cover immediate rendering, blocked floors, stale download prevention, and optional enhancement failures. The browser rasterizes decoded catalog images to at most 1600 pixels on their longest side before alpha cropping, preventing SVG crop operations from silently drawing an invisible product. Browser checks assert visible furniture-body pixels, excluding contact shadows, in an isolated database with a table illustration and synthetic room; they do not modify the shop catalog or send photos to a live provider.

`php artisan roompreview:benchmark` expects 8 to 10 non-sensitive room fixtures matching `tests/Fixtures/room-*.png`. You can specify a different fixture directory with `--path=`. The current command is intentionally offline: it measures preprocessing time, output size, duplicate hashes, and failures. It does not make provider requests, estimate live Gemini cost, or claim scale, fidelity, or verification scores without annotations. Those figures must be collected from a properly labeled set before publishing performance claims.

## Files involved in the placement repair

- `app/Services/FurniturePlacementService.php`, `RoomPreviewRasterService.php`, `RoomPreviewV2Service.php`, `GeminiRoomVisionService.php`, `GeminiService.php`.
- `app/Jobs/AnalyzeRoomImage.php`, `GenerateRoomPreviewV2.php`; `app/Http/Controllers/RoomPreviewV2Controller.php`; `config/roompreview.php`.
- `public/js/room-preview-engine.js`; `public/css/furnicraft.css` (scoped preview containment); `resources/views/preferences/edit.blade.php`, `room/visualize.blade.php`, `recommendations/show.blade.php`.
- `tests/Feature/AutomaticPlacementSafetyTest.php`, `AutomaticRoomFittingPipelineTest.php`, `RoomPreviewRasterPlacementTest.php`, `RoomPreviewWorkflowPresentationTest.php`, `PreviewRetryTest.php`, `PreviewVerificationStatusTest.php`.
- `tests/room-preview-engine.test.js`; `tests/Browser/automatic-room-fit.spec.js`, `seed-mobile.php`; this document.

## Rollback

Set `ROOM_PREVIEW_V2_ENABLED=false`, clear config cache, and keep the old routes in use. The migrations are additive; roll them back with `php artisan migrate:rollback --step=2` only if no v2 data must be retained. That rollback drops only the new analysis table and v2 generation columns.
