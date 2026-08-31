# HRIS Face Recognition Service

Standalone Python microservice that performs face embedding extraction
(enrollment) and 1:1 verification (attendance check-in/out). Reached only by
the Laravel `app` container over the compose network — it is **never** exposed
to end users. Laravel owns authentication and authorization; this service is
trusted because it sits on the private `hris_network`.

Two callers use it differently. The dashboard and the employee portal know who
the employee is from their session, so they enroll through `/embed` and confirm
through `/verify` — a 1:1 comparison. The kiosk terminal has no session to tell
it who is standing in front of the camera, so it calls `/embed` for the probe
and compares the result against every enrolled employee **in Laravel** (1:N).

That N-way search is deliberately not in this service. The embeddings already
live in MySQL, the arithmetic is one dot product over 512 floats, and keeping
the decision rules in PHP puts them under the test suite that guards the rest of
attendance — rather than splitting them across two languages and two deploy
artifacts.

## Endpoints

| Method | Path       | Purpose                                                   |
| ------ | ---------- | --------------------------------------------------------- |
| GET    | `/health`  | Liveness probe. Returns `{"status":"ok"}`.                |
| POST   | `/embed`   | Extract a 512-d ArcFace embedding from a single face.    |
| POST   | `/verify`  | Compare a probe image against a reference embedding.      |

### `POST /embed`

`multipart/form-data` field `image` (any `image/*`).

```json
{ "embedding": [/* 512 floats */], "detected": true, "liveness": "unknown" }
```

### `POST /verify`

`multipart/form-data`:

- `image` — probe photo.
- `reference_embedding` — comma-separated 512 floats (stored on the Laravel side).

```json
{ "verified": true, "distance": 0.312, "liveness": "unknown", "detected": true }
```

`liveness` is `"real" | "spoof" | "unknown"`. The current engine uses a
conservative Laplacian-sharpness heuristic that flags flat print/screen
captures as `"spoof"`. `"unknown"` is treated as a soft pass by Laravel — the
hard anti-buddy-punching guarantee comes from the ArcFace match plus whatever
the caller enforces around it, and that differs by caller: the employee portal
adds the GPS geofence, while the kiosk terminal — which has no usable GPS,
being a fixed device — adds a stricter 1:N threshold with a runner-up margin, a
per-device token, and an optional network allowlist. A trained MiniFASNet ONNX
model can be dropped into `face_engine._assess_liveness` later.

## Configuration

| Env var                    | Default | Meaning                                                       |
| -------------------------- | ------- | ------------------------------------------------------------- |
| `FACE_DISTANCE_THRESHOLD`  | `0.5`   | Cosine distance below which two embeddings are the same face. |

This threshold governs `/verify` only. The kiosk's 1:N thresholds
(`KIOSK_IDENTIFY_THRESHOLD`, `KIOSK_IDENTIFY_MARGIN`) are enforced in Laravel
and are set on the `app` container, not here — a 1:N search gets one chance to
be wrong per enrolled employee, so its bar is deliberately higher than this
one's.

## Models

- **ArcFace** (`buffalo_l`, ~330MB) via `insightface`. Bundled into the image
  at build time so cold starts are fast and there is no runtime CDN
  dependency.
- **Anti-spoofing**: heuristic for now (see above). Designed to be swapped for
  a trained MiniFASNet model without touching the public API.

## Run locally

```bash
podman compose build face-recognition
podman compose up face-recognition

# sanity check
curl http://localhost:5000/health   # only if you temporarily publish the port
```

Inside the compose network Laravel calls it as `http://face-recognition:5000`.

## Privacy

Reference embeddings and photos are stored on the Laravel side (self-hosted
MySQL + local storage). This service keeps no state — embeddings are passed in
per request and discarded. No biometric data leaves the deployment.
