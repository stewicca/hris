"""HTTP entrypoint for the face-recognition microservice.

Endpoints
---------
GET  /health        → liveness probe for compose healthchecks.
POST /embed         → extract a 512-d ArcFace embedding from a single face.
POST /verify        → compare a probe image against a reference embedding (1:1).

The service is stateless: embeddings live in the Laravel MySQL database and
are passed in per request. It is reached only from the Laravel `app` container
over the internal compose network; it must never be exposed to the public
network because the Laravel layer owns authentication and authorization.
"""

from __future__ import annotations

import os
from typing import Literal

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel

from app.face_engine import FaceEngine, read_upload

THRESHOLD = float(os.environ.get("FACE_DISTANCE_THRESHOLD", "0.5"))

# Load once at import time so workers reuse the warmed model.
engine = FaceEngine(threshold=THRESHOLD)

app = FastAPI(title="HRIS Face Recognition", version="1.0.0")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


class EmbedResponse(BaseModel):
    embedding: list[float] | None
    detected: bool
    liveness: Literal["real", "spoof", "unknown"]


class VerifyResponse(BaseModel):
    verified: bool
    distance: float
    liveness: Literal["real", "spoof", "unknown"]
    detected: bool


@app.post("/embed", response_model=EmbedResponse)
async def embed(image: UploadFile = File(...)) -> EmbedResponse:
    if not image.content_type or not image.content_type.startswith("image/"):
        raise HTTPException(status_code=422, detail="image must be an image/* file")
    result = engine.embed(await _read(image))
    return EmbedResponse(embedding=result.embedding, detected=result.face_detected, liveness=result.liveness)


@app.post("/verify", response_model=VerifyResponse)
async def verify(
    image: UploadFile = File(...),
    reference_embedding: str = Form(""),
) -> VerifyResponse:
    """Verify a probe image against a reference embedding.

    `reference_embedding` is a comma-separated list of 512 floats sent as a
    multipart form field (alongside the image). It MUST be annotated with
    Form() — without it FastAPI treats the bare str param as a query parameter
    and ignores the multipart body value, yielding "got 0 elements".
    """
    if not image.content_type or not image.content_type.startswith("image/"):
        raise HTTPException(status_code=422, detail="image must be an image/* file")
    try:
        reference = [float(x) for x in reference_embedding.split(",") if x.strip() != ""]
    except ValueError as exc:
        raise HTTPException(status_code=422, detail="reference_embedding must be a comma-separated float list") from exc
    if len(reference) != 512:
        raise HTTPException(
            status_code=422,
            detail=f"reference_embedding must have 512 elements, got {len(reference)}",
        )

    result = engine.verify(await _read(image), reference)
    return VerifyResponse(
        verified=result.verified,
        distance=result.distance,
        liveness=result.liveness,
        detected=result.face_detected,
    )


async def _read(upload: UploadFile) -> bytes:
    """Read an UploadFile robustly across sync/async storage backends."""
    upload.file.seek(0)
    data = read_upload(upload)
    return data
