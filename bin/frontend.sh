#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
action=${1:?frontend action required}
case "${APP:-}" in
  '') apps=(account admin) ;;
  account|admin) apps=("$APP") ;;
  *) echo 'APP must be account or admin' >&2; exit 2 ;;
esac
case "${MODE:-dev}" in
  dev|preview) export FRONT_MODE=${MODE:-dev} ;;
  *) echo 'MODE must be dev or preview' >&2; exit 2 ;;
esac
export CONTAINER_UID=${CONTAINER_UID:-$(id -u)}
export CONTAINER_GID=${CONTAINER_GID:-$(id -g)}
compose=(docker compose -p "${FRONT_PROJECT:-vf-finance-frontend}" -f docker-compose.frontend.yml)
case "$action" in
  dev) "${compose[@]}" up -d "${apps[@]}" ;;
  install|typecheck|lint|test|build)
    for app in "${apps[@]}"; do
      if [[ $action == install ]]; then
        "${compose[@]}" run --rm --no-deps "$app" npm ci
      else
        "${compose[@]}" run --rm --no-deps "$app" npm run "$action"
      fi
    done ;;
  prod-build|prod-test)
    for app in "${apps[@]}"; do
      if [[ $action == prod-build ]]; then
        docker build --build-arg "APP=$app" -f docker/frontend/Dockerfile \
          -t "vf-finance/$app:${FRONT_IMAGE_TAG:-local}" .
      else
        APP="$app" bin/frontend-production-test.sh
      fi
    done ;;
  e2e)
    if [[ $FRONT_MODE == preview ]]; then bin/frontend.sh build; fi
    "${compose[@]}" up -d "${apps[@]}"
    for app in "${apps[@]}"; do
      "${compose[@]}" run --rm --no-deps -w "/workspace/apps/$app" playwright npm run test:e2e
    done ;;
  *) echo "Unknown action: $action" >&2; exit 2 ;;
esac
