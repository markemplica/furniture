"""Build smaller public display assets from unchanged originals (requires Pillow with AVIF/WebP)."""

import argparse
import hashlib
import json
import math
from pathlib import Path

from PIL import Image, ImageChops, ImageStat, features


ROOT = Path(__file__).resolve().parents[1]
IMAGES = ROOT / 'public' / 'images'
OUTPUT = IMAGES / 'optimized'
SOURCES = ['lance-furniture-shop-logo-natural.png', 'lance-favicon-circle.png',
           'home-background-natural.png', 'auth-background-natural.png']


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def psnr(source, encoded):
    with Image.open(encoded) as decoded:
        difference = ImageChops.difference(source.convert('RGB'), decoded.convert('RGB'))
        mse = sum(value ** 2 for value in ImageStat.Stat(difference).rms) / 3
    return round(10 * math.log10(255 ** 2 / mse), 2) if mse else None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', type=Path, required=True, help='Write size/quality evidence outside public assets.')
    args = parser.parse_args()
    if not features.check('webp') or not features.check('avif'):
        raise SystemExit('Pillow must support both WebP and AVIF. No originals will be changed.')
    OUTPUT.mkdir(exist_ok=True)
    hashes = {name: sha256(IMAGES / name) for name in SOURCES}
    report = {'sources': {}, 'variants': []}
    for name in SOURCES:
        with Image.open(IMAGES / name) as image:
            report['sources'][name] = {'sha256': hashes[name], 'bytes': (IMAGES / name).stat().st_size,
                                      'width': image.width, 'height': image.height}

    def record(path, source, image, **extra):
        report['variants'].append({'file': path.name, 'source': source, 'bytes': path.stat().st_size,
                                   'width': image.width, 'height': image.height, **extra})

    for source, prefix in [('lance-furniture-shop-logo-natural.png', 'lance-logo'),
                           ('lance-favicon-circle.png', 'lance-logo-circle')]:
        with Image.open(IMAGES / source) as image:
            for side in [64, 128]:
                resized = image.resize((side, side), Image.Resampling.LANCZOS)
                target = OUTPUT / f'{prefix}-{side}.webp'
                resized.save(target, 'WEBP', lossless=True, method=6)
                record(target, source, resized)

    with Image.open(IMAGES / 'lance-favicon-circle.png') as image:
        for side in [32, 48]:
            resized = image.resize((side, side), Image.Resampling.LANCZOS)
            target = OUTPUT / f'lance-favicon-{side}.png'
            resized.save(target, 'PNG', optimize=True)
            with Image.open(target) as check:
                assert check.mode == 'RGBA' and check.getpixel((0, 0))[3] == 0, 'Favicon transparency changed'
            record(target, 'lance-favicon-circle.png', resized)
    with Image.open(IMAGES / 'lance-furniture-shop-logo-natural.png') as image:
        resized = image.resize((180, 180), Image.Resampling.LANCZOS)
        target = OUTPUT / 'lance-apple-touch-180.png'
        resized.save(target, 'PNG', optimize=True)
        record(target, 'lance-furniture-shop-logo-natural.png', resized)

    for source, prefix in [('home-background-natural.png', 'home-background'),
                           ('auth-background-natural.png', 'auth-background')]:
        with Image.open(IMAGES / source) as image:
            image = image.convert('RGB')
            for suffix, format, qualities, options in [
                ('avif', 'AVIF', [75, 85, 90], {'speed': 6}),
                ('webp', 'WEBP', [88, 92, 95], {'method': 6}),
                ('jpg', 'JPEG', [90, 94, 97], {'optimize': True, 'progressive': True}),
            ]:
                target = OUTPUT / f'{prefix}.{suffix}'
                for quality in qualities:
                    image.save(target, format, quality=quality, **options)
                    score = psnr(image, target)
                    if score is None or score >= 36:
                        break
                else:
                    raise RuntimeError(f'Quality check failed for {target.name}; original preserved')
                assert target.stat().st_size < (IMAGES / source).stat().st_size
                record(target, source, image, quality=quality, psnr_db=score)

    assert all(sha256(IMAGES / name) == digest for name, digest in hashes.items()), 'An original changed'
    report['originals_unchanged'] = True
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
