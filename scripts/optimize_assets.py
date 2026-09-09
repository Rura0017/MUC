"""Regenerate the favicon: python -m pip install Pillow"""

from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parent.parent


def main():
    # The full-size original remains available for social sharing.
    with Image.open(ROOT / "image/ogp.png") as source:
        icon = source.convert("RGBA")
        icon.thumbnail((64, 64), Image.Resampling.LANCZOS)
        icon.save(ROOT / "image/favicon.png", optimize=True)


if __name__ == "__main__":
    main()
