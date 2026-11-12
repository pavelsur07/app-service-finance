#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
action=${1:-test}
case "$action" in test|serve|stop) ;; *) echo "Unknown action: $action" >&2; exit 2;; esac
case "${APP:-}" in '') apps=(account admin);; account|admin) apps=("$APP");; *) echo 'APP must be account or admin' >&2; exit 2;; esac
export AUTH_MODE=${MODE:-dev}
case "$AUTH_MODE" in dev|preview|production) ;; *) echo 'MODE must be dev, preview or production' >&2; exit 2;; esac
export CONTAINER_UID=${CONTAINER_UID:-$(id -u)} CONTAINER_GID=${CONTAINER_GID:-$(id -g)}
compose=(docker compose -p "${AUTH_PROJECT:-vf-spa-http}" -f docker-compose.frontend-auth.yml)
if [[ $action == stop ]]; then
  "${compose[@]}" --profile production --profile test down --remove-orphans
  exit 0
fi
if [[ $action == serve ]]; then
  [[ $AUTH_MODE != production ]] || { echo 'Interactive mode supports dev or preview' >&2; exit 2; }
  compose+=(-f docker-compose.frontend-auth.local.yml)
fi
if [[ $AUTH_MODE == production ]]; then
  export AUTH_ACCOUNT_ALIAS=account-source AUTH_ADMIN_ALIAS=admin-source AUTH_APP_ALIAS=app-backend
  export AUTH_GATEWAY_ACCOUNT_ALIAS=account.auth.test AUTH_GATEWAY_ADMIN_ALIAS=admin.auth.test AUTH_GATEWAY_APP_ALIAS=app.auth.test
fi
mkdir -p .frontend-auth/certs .frontend-auth/var
cleanup() { "${compose[@]}" --profile production --profile test down --remove-orphans >/dev/null; }
trap cleanup EXIT
"${compose[@]}" build playwright
# Keys are ephemeral, ignored test artifacts. Never use a production certificate.
docker run --rm --user "$CONTAINER_UID:$CONTAINER_GID" -v "$PWD/.frontend-auth/certs:/certs" \
  vf-finance/auth-playwright:local bash -ec '
    if [[ ! -f /certs/ca.crt ]] || ! openssl x509 -checkend 3600 -noout -in /certs/ca.crt >/dev/null; then
      openssl req -x509 -newkey rsa:2048 -nodes -keyout /certs/ca.key -out /certs/ca.crt -days 7 -subj /CN=Finance-Local-Test-CA -addext basicConstraints=critical,CA:TRUE 2>/dev/null
      openssl req -newkey rsa:2048 -nodes -keyout /certs/server.key -out /certs/server.csr -subj /CN=account.auth.test 2>/dev/null
      printf "subjectAltName=DNS:account.auth.test,DNS:admin.auth.test,DNS:app.auth.test\nextendedKeyUsage=serverAuth\n" > /certs/server.ext
      openssl x509 -req -in /certs/server.csr -CA /certs/ca.crt -CAkey /certs/ca.key -CAcreateserial -out /certs/server.crt -days 7 -extfile /certs/server.ext 2>/dev/null
    fi'
python3 bin/frontend-auth-routes.py
"${compose[@]}" up -d --wait site-postgres site-redis site-php-cli
"${compose[@]}" exec -T site-php-cli php bin/console doctrine:migrations:migrate --no-interaction
"${compose[@]}" exec -T site-php-cli php tests/Support/Spa/seed-http.php
"${compose[@]}" up -d site-php-fpm site
if [[ $AUTH_MODE == production ]]; then
  APP= MODE=dev bin/frontend.sh prod-build
  "${compose[@]}" --profile production up -d --wait account-image admin-image gateway
else
  if [[ $AUTH_MODE == preview ]]; then bin/frontend.sh build; fi
  "${compose[@]}" up -d account admin
fi
if [[ $action == serve ]]; then
  trap - EXIT
  echo 'Local auth stack ready. See apps/README.md for test hosts and credentials.'
  exit 0
fi
for app in "${apps[@]}"; do
  APP="$app" "${compose[@]}" run --rm --no-deps playwright
 done
