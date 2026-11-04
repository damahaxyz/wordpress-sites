#!/usr/bin/env python3
"""Create English-localized d1–d3 gallery images without altering product pixels."""

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "product-assets/focus-spark-atmosphere-wand/source"
OUTPUT = ROOT / "product-assets/focus-spark-atmosphere-wand/ready"
FONT = Path("/System/Library/Fonts/Supplemental/Arial.ttf")
BOLD = Path("/System/Library/Fonts/Supplemental/Arial Bold.ttf")


def font(size: int, *, bold: bool = False) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(str(BOLD if bold else FONT), size=size)


def fit_font(draw: ImageDraw.ImageDraw, text: str, box: tuple[int, int, int, int],
             max_size: int, min_size: int = 10, *, bold: bool = False) -> ImageFont.FreeTypeFont:
    max_width = box[2] - box[0]
    max_height = box[3] - box[1]
    for size in range(max_size, min_size - 1, -1):
        candidate = font(size, bold=bold)
        bounds = draw.multiline_textbbox((0, 0), text, font=candidate, spacing=3)
        if bounds[2] - bounds[0] <= max_width and bounds[3] - bounds[1] <= max_height:
            return candidate
    return font(min_size, bold=bold)


def label(draw: ImageDraw.ImageDraw, box: tuple[int, int, int, int], text: str,
          *, background: str, max_size: int, bold: bool = False,
          anchor: str = "lm", align: str = "left", padding: int = 4) -> None:
    draw.rectangle(box, fill=background)
    inner = (box[0] + padding, box[1] + padding, box[2] - padding, box[3] - padding)
    selected = fit_font(draw, text, inner, max_size, bold=bold)
    if anchor == "mm":
        position = ((box[0] + box[2]) // 2, (box[1] + box[3]) // 2)
    else:
        position = (box[0] + padding, (box[1] + box[3]) // 2)
    draw.multiline_text(position, text, font=selected, fill="#f2f4f4", anchor=anchor,
                        align=align, spacing=3)


def localize_d1() -> None:
    image = Image.open(SOURCE / "d1.jpg").convert("RGB")
    draw = ImageDraw.Draw(image)
    label(draw, (472, 60, 752, 134), "MADE FOR\nOUTDOOR MOMENTS",
          background="#07131d", max_size=23, bold=True, anchor="mm", align="center", padding=8)
    image.save(OUTPUT / "d1-en.png", optimize=True)


def localize_d2() -> None:
    image = Image.open(SOURCE / "d2.jpg").convert("RGB")
    draw = ImageDraw.Draw(image)
    bg = "#212121"

    label(draw, (202, 164, 628, 250), "GET TO KNOW IT BETTER",
          background=bg, max_size=32, bold=False, anchor="mm", align="center", padding=10)
    label(draw, (240, 278, 345, 313), "Effect outlet", background=bg, max_size=15)
    label(draw, (250, 366, 400, 424), "Material chamber", background=bg, max_size=15)
    label(draw, (34, 493, 136, 550), "Output\nadjustment", background=bg, max_size=15)
    label(draw, (225, 486, 340, 538), "Safety lock", background=bg, max_size=14)
    label(draw, (225, 530, 352, 578), "Start button", background=bg, max_size=14)
    label(draw, (242, 764, 365, 803), "Gas filling port", background=bg, max_size=14)
    label(draw, (426, 375, 535, 413), "Effect tube", background=bg, max_size=15)
    label(draw, (454, 414, 542, 470), "Stop block", background=bg, max_size=14)
    label(draw, (648, 321, 756, 360), "Locating hole", background=bg, max_size=14)
    label(draw, (646, 716, 770, 766), "Chamber\nlocking slot", background=bg, max_size=14)
    image.save(OUTPUT / "d2-en.png", optimize=True)


def localize_d3() -> None:
    image = Image.open(SOURCE / "d3.jpg").convert("RGB")
    draw = ImageDraw.Draw(image)
    bg = "#101010"

    label(draw, (94, 160, 752, 250), "REFILLABLE CHAMBER · LASTING SPARKLE",
          background=bg, max_size=31, bold=True, anchor="mm", align="center", padding=10)
    label(draw, (246, 256, 650, 296), "KNOW MORE · ENJOY MORE",
          background=bg, max_size=16, anchor="mm", align="center")
    label(draw, (258, 380, 400, 464), "Material chamber", background=bg, max_size=15)
    label(draw, (258, 566, 398, 656), "Gas inlet", background=bg, max_size=16)
    label(draw, (258, 670, 400, 758), "Safety lock", background=bg, max_size=16)
    label(draw, (258, 762, 404, 850), "Start switch", background=bg, max_size=16)
    label(draw, (258, 902, 398, 998), "Handle", background=bg, max_size=16)
    label(draw, (491, 334, 760, 422), "Replaceable material chamber\nUp to 19 minutes each",
          background=bg, max_size=18, bold=False, anchor="mm", align="center", padding=8)
    label(draw, (493, 681, 756, 790), "Bottom gas filling port\nFor dedicated high-pressure gas",
          background=bg, max_size=18, bold=False, anchor="mm", align="center", padding=8)
    label(draw, (285, 1078, 405, 1275), "MATERIAL\nCHAMBER\nINCLUDED",
          background=bg, max_size=18, bold=True, anchor="mm", align="center", padding=5)
    label(draw, (285, 1324, 405, 1555), "DEDICATED\nGAS CANISTER\nINCLUDED",
          background=bg, max_size=17, bold=True, anchor="mm", align="center", padding=5)
    label(draw, (488, 1074, 762, 1164), "TURBINE-GUIDED\nAIRFLOW",
          background=bg, max_size=22, bold=False, anchor="mm", align="center", padding=8)
    image.save(OUTPUT / "d3-en.png", optimize=True)


def main() -> None:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    localize_d1()
    localize_d2()
    localize_d3()
    for name in ("d1-en.png", "d2-en.png", "d3-en.png"):
        print(OUTPUT / name)


if __name__ == "__main__":
    main()
