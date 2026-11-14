# Brand display assets

The original PNG logos/backgrounds remain in `public/images`. Shared views use small lossless WebP logo variants with 1x/2x sources, transparent 32/48px PNG favicons, and a 180px PNG touch icon. CSS selects AVIF/WebP backgrounds with a JPEG fallback. Backgrounds keep their original dimensions, aspect ratios and existing CSS positioning; these are deterministic encodings, not regenerated artwork.

To rebuild after an approved change to an original image, use Python with Pillow supporting AVIF and WebP:

```powershell
python tools/optimize-brand-assets.py --report "$env:TEMP\furnicraft-brand-assets.json"
```

The script writes only variants in `public/images/optimized`, records source SHA-256 hashes, checks original preservation and favicon transparency, and checks a minimum decoded background PSNR of 36 dB. This metric supports visual QA; it does not replace checking actual browser screenshots. Pillow is a development-time tool, not an application server dependency.

Deploy the optimized assets together with the updated Blade/CSS files. If assets are regenerated under the same filenames, invalidate their CDN/browser cache or version their URLs as part of deployment. Retain originals for rollback. Do not use this brand-asset pipeline to alter catalog product photos or private customer room images.
