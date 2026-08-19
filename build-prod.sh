#!/usr/bin/env bash
# =============================================================================
# Build the production images.
#
# Run this on a laptop or in CI, NEVER on the VPS. The image build runs both
# Vite builds (dashboard + employee SPA); rollup/esbuild on a small VPS either
# thrashes swap for tens of minutes or is killed outright.
#
# Ship the result to the server one of two ways:
#
#   Registry (preferred once a registry exists):
#       ./build-prod.sh --push ghcr.io/<owner>/hris
#       # then, on the server, with IMAGE_PREFIX=ghcr.io/<owner>/hris in .env.prod:
#       ./update-prod.sh --pull
#
#   Direct transfer (no registry needed):
#       ./build-prod.sh --save
#       scp hris-images.tar.gz <your-server>:~/hris/
#       # then, on the server:
#       ./update-prod.sh --load hris-images.tar.gz
#
# The tag layout matches docker-compose.prod.yml's ${IMAGE_PREFIX}-<name>:prod,
# so --push <prefix> and IMAGE_PREFIX=<prefix> must be given the same value.
#
# The face-recognition image is large (buffalo_l model pack is ~330 MB on its
# own); expect a slow first transfer.
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")"

# esbuild opens more files than the default 1024 hard limit allows while
# resolving the npm workspaces, so raise it for the build.
ULIMIT=(--ulimit nofile=65536:65536)

PUSH_PREFIX=""
DO_SAVE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --push)
            PUSH_PREFIX="${2:?--push needs a registry prefix, e.g. ghcr.io/owner/hris}"
            shift 2
            ;;
        --save)
            DO_SAVE=1
            shift
            ;;
        *)
            echo "unknown argument: $1" >&2
            exit 64
            ;;
    esac
done

echo "==> Building app image (PHP-FPM + supervisor)..."
docker build "${ULIMIT[@]}" -f Dockerfile.prod --target app -t localhost/hris-app:prod .

echo "==> Building nginx image (Laravel public + employee SPA)..."
docker build "${ULIMIT[@]}" -f Dockerfile.prod --target nginx -t localhost/hris-nginx:prod .

echo "==> Building face-recognition image..."
docker build "${ULIMIT[@]}" -t localhost/hris-face-recognition:prod ./services/face-recognition

if [[ -n "$PUSH_PREFIX" ]]; then
    for image in app nginx face-recognition; do
        echo "==> Pushing ${PUSH_PREFIX}-${image}:prod ..."
        docker tag "localhost/hris-${image}:prod" "${PUSH_PREFIX}-${image}:prod"
        docker push "${PUSH_PREFIX}-${image}:prod"
    done
fi

if [[ "$DO_SAVE" -eq 1 ]]; then
    echo "==> Saving images to hris-images.tar.gz ..."
    docker save \
        localhost/hris-app:prod \
        localhost/hris-nginx:prod \
        localhost/hris-face-recognition:prod | gzip > hris-images.tar.gz
    echo "    Transfer with: scp hris-images.tar.gz <your-server>:~/hris/"
    echo "    Then on the server: ./update-prod.sh --load hris-images.tar.gz"
fi

echo
echo "==> Done."
echo "    First deploy:  docker compose --env-file .env.prod -f docker-compose.prod.yml up -d"
echo "                   docker compose --env-file .env.prod -f docker-compose.prod.yml \\"
echo "                       exec app php artisan db:seed --class=ProductionSeeder --force"
echo "    Every update:  ./update-prod.sh --pull   (or --load <tarball>)"
