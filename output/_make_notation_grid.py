"""
Generate an 18-move reference grid (uppercase, wide, rotations/slices)
matching the classic notation chart layout.

Renders each cube via the local VisualCube PHP service, then composites
into a 3x6 grid PNG with labels.

Usage:  python output/_make_notation_grid.py
Output: output/notation_grid.png
"""

import os
import sys
import time
import urllib.parse
import urllib.request

from PIL import Image, ImageDraw, ImageFont

BASE_URL = "http://localhost:8080/visualcube.php"
OUT_DIR = os.path.dirname(os.path.abspath(__file__))
CACHE_DIR = os.path.join(OUT_DIR, "notation_cache")
os.makedirs(CACHE_DIR, exist_ok=True)

# 3 rows × 6 cols, exactly as in the reference image
ROWS = [
    ["U",  "D",  "R",  "L",  "F",  "B"],   # face moves
    ["u",  "d",  "r",  "l",  "f",  "b"],   # wide moves (2 layers)
    ["x",  "y",  "z",  "M",  "E",  "S"],   # rotations + slice moves
]

# Per-cell render size (cube image)
CUBE_PX = 220
# Cell padding / label area
LABEL_H = 48
CELL_PAD_X = 18
CELL_PAD_Y = 12
GRID_BG = (245, 245, 245)
LABEL_COLOR = (20, 20, 20)


def fetch_move(move: str, cb: int) -> bytes:
    """Render one cube with both `case=` (apply move to facelets) and
    `move=` (draw the rotation arrow) at CUBE_PX size on transparent bg."""
    encoded = urllib.parse.quote(move, safe="")
    qs = (
        f"fmt=png&size={CUBE_PX}&bg=t"
        f"&case={encoded}&move={encoded}"
        f"&_cb={cb}"
    )
    url = f"{BASE_URL}?{qs}"
    with urllib.request.urlopen(url, timeout=30) as r:
        return r.read()


def get_cube_image(move: str, cb: int) -> Image.Image:
    """Fetch (or load cached) PNG for one move and return as RGBA Image."""
    safe = move.replace("'", "p")
    path = os.path.join(CACHE_DIR, f"move_{safe}.png")
    data = fetch_move(move, cb)
    with open(path, "wb") as f:
        f.write(data)
    return Image.open(path).convert("RGBA")


def load_font(size: int) -> ImageFont.ImageFont:
    for name in ("arial.ttf", "DejaVuSans.ttf", "Helvetica.ttf"):
        try:
            return ImageFont.truetype(name, size)
        except (OSError, IOError):
            continue
    return ImageFont.load_default()


def main() -> int:
    cb = int(time.time())

    n_rows = len(ROWS)
    n_cols = len(ROWS[0])
    cell_w = CUBE_PX + 2 * CELL_PAD_X
    cell_h = CUBE_PX + LABEL_H + 2 * CELL_PAD_Y
    W = n_cols * cell_w
    H = n_rows * cell_h

    grid = Image.new("RGB", (W, H), GRID_BG)
    draw = ImageDraw.Draw(grid)
    font = load_font(34)

    for r, row in enumerate(ROWS):
        for c, move in enumerate(row):
            print(f"  [{r},{c}] {move!r}", flush=True)
            try:
                cube = get_cube_image(move, cb)
            except Exception as e:
                print(f"    ! failed: {e}", file=sys.stderr)
                continue

            # Fit cube into the cell preserving aspect
            cube.thumbnail((CUBE_PX, CUBE_PX), Image.LANCZOS)
            cx = c * cell_w + (cell_w - cube.width) // 2
            cy = r * cell_h + CELL_PAD_Y + (CUBE_PX - cube.height) // 2
            grid.paste(cube, (cx, cy), cube)

            # Label centred below cube
            bbox = draw.textbbox((0, 0), move, font=font)
            tw = bbox[2] - bbox[0]
            th = bbox[3] - bbox[1]
            tx = c * cell_w + (cell_w - tw) // 2
            ty = r * cell_h + CELL_PAD_Y + CUBE_PX + (LABEL_H - th) // 2 - bbox[1]
            draw.text((tx, ty), move, fill=LABEL_COLOR, font=font)

    out = os.path.join(OUT_DIR, "notation_grid.png")
    grid.save(out)
    print(f"saved {out}  ({grid.size[0]}x{grid.size[1]})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
