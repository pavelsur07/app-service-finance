#!/usr/bin/env bash
# Browser contract against an immutable nginx image, never a Vite server.
set -euo pipefail
cd "$(dirname "$0")/../.."
case "${APP:-}" in account|admin) ;; *) echo 'APP must be account or admin' >&2; exit 2 ;; esac
image=${IMAGE:-vf-finance/$APP:${FRONT_IMAGE_TAG:-local}}
network="vf-spa-${APP}-$$"
container="${network}-nginx"
cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$network" >/dev/null
docker run -d --name "$container" --network "$network" --network-alias "$APP" \
    --read-only --tmpfs /var/cache/nginx --tmpfs /var/run "$image" >/dev/null
# Runtime must contain only the static artifact, never a workspace or dependencies.
docker exec "$container" sh -c 'test ! -e /workspace && test ! -e /usr/share/nginx/html/node_modules && test ! -e /usr/share/nginx/html/package.json'
docker run --rm --init --shm-size=1g --network "$network" \
    --user "$(id -u):$(id -g)" -e HOME=/tmp -e E2E_CONTAINER=1 -e E2E_MODE=production \
    -v "$PWD/apps:/workspace/apps" -w "/workspace/apps/$APP" \
    mcr.microsoft.com/playwright:v1.56.1-noble npm run test:e2e
