#!/usr/bin/env python3
"""Add verified English labels to the retouched 1688 product photographs."""

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[1] / "product-assets" / "1079994456070"
READY = ROOT / "ready"
READY.mkdir(parents=True, exist_ok=True)

FONT_REGULAR = "/System/Library/Fonts/Supplemental/Arial.ttf"
FONT_BOLD = "/System/Library/Fonts/Supplemental/Arial Bold.ttf"
TITLE_FONT = ImageFont.truetype(FONT_BOLD, 30)
BODY_FONT = ImageFont.truetype(FONT_REGULAR, 27)
COLOR_FONT = ImageFont.truetype(FONT_BOLD, 30)

INK = (39, 47, 49, 255)
MUTED = (70, 78, 82, 255)
ACCENT = (180, 120, 57, 255)
PANEL = (255, 255, 255, 237)


def label(draw: ImageDraw.ImageDraw, box: tuple[int, int, int, int], title: str, body: str) -> None:
    x1, y1, x2, y2 = box
    draw.rounded_rectangle(box, radius=21, fill=PANEL, outline=(224, 219, 212, 255), width=2)
    draw.rounded_rectangle((x1 + 19, y1 + 23, x1 + 25, y2 - 23), radius=3, fill=ACCENT)
    draw.text((x1 + 43, y1 + 24), title, font=TITLE_FONT, fill=INK)
    draw.text((x1 + 43, y1 + 70), body, font=BODY_FONT, fill=MUTED)


for color in ("clear", "pink"):
    image = Image.open(ROOT / "retouched" / f"{color}.png").convert("RGB")
    image.save(READY / f"scalp-applicator-comb-{color}-with-accessories.webp", "WEBP", quality=88)

    feature_image = image.convert("RGBA")
    overlay = Image.new("RGBA", feature_image.size)
    draw = ImageDraw.Draw(overlay)

    # Labels translate and clarify the visible parts in the supplier photo.
    label(draw, (40, 45, 445, 185), "ROLLER TIPS", "Apply along the scalp")
    label(draw, (795, 45, 1215, 185), "10 mL RESERVOIR", "See the liquid level")
    label(draw, (40, 790, 445, 930), "DUST COVER", "Covers the comb tips")
    label(draw, (905, 405, 1215, 545), "DROPPER", "Makes filling easier")
    label(draw, (40, 1060, 505, 1200), "FILL-PORT STOPPER", "Seals the reservoir")

    draw.rounded_rectangle((1010, 1105, 1215, 1175), radius=35, fill=ACCENT)
    variant_name = "CLEAR" if color == "clear" else "PINK"
    bbox = draw.textbbox((0, 0), variant_name, font=COLOR_FONT)
    width = bbox[2] - bbox[0]
    draw.text((1112 - width / 2, 1121), variant_name, font=COLOR_FONT, fill="white")

    feature_image = Image.alpha_composite(feature_image, overlay).convert("RGB")
    feature_image.save(READY / f"scalp-applicator-comb-{color}-features-en.webp", "WEBP", quality=90)
