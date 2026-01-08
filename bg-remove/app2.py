from fastapi import FastAPI, File, UploadFile, Header, HTTPException
from fastapi.responses import Response
from rembg import remove

app = FastAPI()

# Optional shared secret. If you set BG_REMOVE_TOKEN in docker-compose,
# Laravel must send X-BG-Token header.
import os
TOKEN = os.environ.get("BG_REMOVE_TOKEN", "").strip()

@app.get("/health")
def health():
    return {"ok": True}

@app.post("/remove")
async def remove_bg(
    image: UploadFile = File(...),
    x_bg_token: str | None = Header(default=None)
):
    if TOKEN:
        if not x_bg_token or x_bg_token != TOKEN:
            raise HTTPException(status_code=401, detail="Unauthorized")

    content = await image.read()
    if not content:
        raise HTTPException(status_code=400, detail="Empty upload")

    # rembg returns bytes (PNG with alpha) by default
    try:
        out = remove(content)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Removal failed: {str(e)}")

    return Response(content=out, media_type="image/png")
