#!/usr/bin/env python3
"""Compress inspected English PNG masters using cwebp; preserve pixel dimensions."""
import hashlib
import json
from pathlib import Path
import subprocess
from PIL import Image

root = Path(__file__).resolve().parents[1] / 'product-assets/2026-firework-bubble-machine'
manifest = {}
for source in sorted((root / 'retouched').glob('*.png')):
    target = root / 'ready' / (source.stem + '.webp')
    quality = 86
    subprocess.run(['cwebp', '-quiet', '-q', str(quality), '-m', '6', '-sharp_yuv',
                    '-metadata', 'none', str(source), '-o', str(target)], check=True)
    with Image.open(source) as original, Image.open(target) as output:
        assert original.size == output.size
        width, height = output.size
    assert target.stat().st_size < 500000, target.name
    manifest[target.name] = {
        'source': source.name, 'width': width, 'height': height,
        'quality': quality, 'before_bytes': source.stat().st_size,
        'after_bytes': target.stat().st_size,
        'sha256': hashlib.sha256(target.read_bytes()).hexdigest(),
    }
assert len(manifest) == 7, 'Expected the seven inspected English assets'
(root / 'ready/manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(json.dumps(manifest, indent=2))
