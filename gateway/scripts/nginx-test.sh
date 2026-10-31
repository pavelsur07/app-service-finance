#!/usr/bin/env bash
# nginx -t конфигов шлюза в контейнере с раскладкой как на хосте (Ubuntu),
# самоподписанными сертификатами и тестовыми секретами. CI (validate) и локально:
#   gateway/scripts/nginx-test.sh          — проверка
#   gateway/scripts/nginx-test.sh --dump   — проверка + nginx -T (эффективный конфиг)
set -euo pipefail

# shellcheck source=layout.sh
source "$(dirname "$0")/layout.sh"

NGINX_IMAGE=${NGINX_IMAGE:-nginx:1.28}
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

gateway_test_secrets "$WORK/secrets.conf"
gateway_layout "$WORK" "$WORK/secrets.conf"

CMD=(nginx -t)
[ "${1:-}" = "--dump" ] && CMD=(nginx -T)
docker run --rm \
    -v "$WORK/nginx:/etc/nginx:ro" \
    -v "$WORK/letsencrypt:/etc/letsencrypt:ro" \
    "$NGINX_IMAGE" "${CMD[@]}"
