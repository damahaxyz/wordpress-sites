#!/usr/bin/env python3
"""Encode the Christmas PNG masters without resizing; requires cwebp and Pillow."""
import hashlib
import json
from pathlib import Path
import subprocess

from PIL import Image

root = Path(__file__).resolve().parents[1] / 'product-assets/focus-spark-atmosphere-wand'
output = root / 'optimized'
output.mkdir(exist_ok=True)
names = [
    'christmas-spark-atmosphere-wand-hero',
    'gift-set-silver-en', 'gift-set-green-en', 'gift-set-black-en',
    'gift-set-pink-en', 'gift-set-yellow-en', 'd1-en', 'd2-en', 'd3-en',
    'christmas-storefront-desktop', 'christmas-storefront-mobile',
    'replacement-material-chamber-en', 'butane-gas-canister-en',
]
rows = []
for name in names:
    source = root / 'ready' / f'{name}.png'
    target = output / f'{name}.webp'
    quality = 90 if name in ('d2-en', 'd3-en') else 84
    subprocess.run([
        'cwebp', '-quiet', '-q', str(quality), '-m', '6', '-sharp_yuv',
        '-metadata', 'none', str(source), '-o', str(target),
    ], check=True)
    with Image.open(source) as original, Image.open(target) as encoded:
        assert original.size == encoded.size
        width, height = encoded.size
    rows.append({
        'source': source.name, 'optimized': target.name,
        'width': width, 'height': height, 'quality': quality,
        'before_bytes': source.stat().st_size, 'after_bytes': target.stat().st_size,
        'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
        'sha256': hashlib.sha256(target.read_bytes()).hexdigest(),
    })
(output / 'manifest.json').write_text(json.dumps(rows, indent=2) + '\n')
print(json.dumps({
    'images': len(rows),
    'before_bytes': sum(row['before_bytes'] for row in rows),
    'after_bytes': sum(row['after_bytes'] for row in rows),
}))
