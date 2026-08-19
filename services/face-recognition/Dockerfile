# Face recognition microservice.
#
# Debian-based (not Alpine) because onnxruntime and OpenCV wheels ship glibc
# builds that don't run on musl. The image is heavier than the PHP/Alpine
# containers but is isolated and only reached internally by the Laravel app.

FROM docker.io/library/python:3.12-slim AS base

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# Runtime libs needed by OpenCV/onnxruntime (libGL, libglib, libgomp) plus a
# C/C++ toolchain because insightface compiles a Cython extension at install
# time. gcc/g++ are kept in the final image — removing them via a multi-stage
# copy saves little (OpenCV/onnxruntime pull libc anyway) and complicates the
# build; the image is internal-only.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgl1 \
        libglib2.0-0 \
        libgomp1 \
        build-essential \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./
RUN pip install --root-user-action=ignore -r requirements.txt

COPY app ./app

# Pre-download the buffalo_l model pack (~330MB) at build time so cold starts
# don't hit the insightface CDN. Kept inside the image's model root.
RUN python -c "from insightface.app import FaceAnalysis; FaceAnalysis(name='buffalo_l', providers=['CPUExecutionProvider']).prepare(ctx_id=0, det_size=(640, 640))"

EXPOSE 5000

# Single worker by default — the model is large and CPU-bound; scale out via
# compose replicas rather than per-process threads. --workers can be raised
# once measurements confirm memory headroom.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "5000", "--workers", "1"]
