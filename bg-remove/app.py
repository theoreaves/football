from fastapi import FastAPI, File, UploadFile, Header, HTTPException, Query
from fastapi.responses import Response
import os

from PIL import Image
import numpy as np
from io import BytesIO
from collections import deque

app = FastAPI()
TOKEN = os.environ.get("BG_REMOVE_TOKEN", "").strip()

@app.get("/health")
def health():
    return {"ok": True}

def magic_wand_remove_outer_background(im_rgba: Image.Image, tol: int = 25) -> Image.Image:
    """
    Remove ONLY background pixels connected to the image border whose color is within `tol`
    of the sampled background color. Keeps interior regions even if they match the bg color.
    """
    arr = np.array(im_rgba.convert("RGBA"), dtype=np.uint8)
    h, w, _ = arr.shape
    rgb = arr[:, :, :3].astype(np.int16)
    alpha = arr[:, :, 3].astype(np.uint8)

    # Sample background color from corners (average)
    corners = np.array([
        rgb[0, 0], rgb[0, w-1], rgb[h-1, 0], rgb[h-1, w-1]
    ], dtype=np.int16)
    bg = corners.mean(axis=0)  # (r,g,b)

    # Build a boolean "similar to bg" mask (Euclidean distance in RGB)
    diff = rgb - bg
    dist = np.sqrt((diff * diff).sum(axis=2))
    similar = dist <= tol

    # Flood-fill from border through "similar" pixels
    visited = np.zeros((h, w), dtype=bool)
    q = deque()

    def push(y, x):
        if 0 <= y < h and 0 <= x < w and (not visited[y, x]) and similar[y, x]:
            visited[y, x] = True
            q.append((y, x))

    # Start from all border pixels
    for x in range(w):
        push(0, x)
        push(h-1, x)
    for y in range(h):
        push(y, 0)
        push(y, w-1)

    dirs = [(1,0), (-1,0), (0,1), (0,-1)]
    while q:
        y, x = q.popleft()
        for dy, dx in dirs:
            push(y + dy, x + dx)

    # visited == outer background region; make it transparent
    alpha[visited] = 0
    arr[:, :, 3] = alpha

    return Image.fromarray(arr, mode="RGBA")

@app.post("/remove-wand")
async def remove_bg_wand(
    image: UploadFile = File(...),
    x_bg_token: str | None = Header(default=None),
    tol: int = Query(default=25, ge=0, le=150),
):
    if TOKEN:
        if not x_bg_token or x_bg_token != TOKEN:
            raise HTTPException(status_code=401, detail="Unauthorized")

    content = await image.read()
    if not content:
        raise HTTPException(status_code=400, detail="Empty upload")

    try:
        im = Image.open(BytesIO(content))
        out_im = magic_wand_remove_outer_background(im, tol=tol)
        buf = BytesIO()
        out_im.save(buf, format="PNG")
        return Response(content=buf.getvalue(), media_type="image/png")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Magic-wand removal failed: {str(e)}")
