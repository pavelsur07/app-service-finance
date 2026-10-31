#!/usr/bin/env bash
# nginx -t конфигов шлюза в контейнере с раскладкой как на хосте (Ubuntu) и
# самоподписанными сертификатами-заглушками. CI (deploy-gateway.yml, validate) и локально:
#   gateway/scripts/nginx-test.sh          — проверка
#   gateway/scripts/nginx-test.sh --dump   — проверка + nginx -T (эффективный конфиг)
set -euo pipefail

NGINX_IMAGE=${NGINX_IMAGE:-nginx:1.28}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

ETC=$WORK/nginx
LE=$WORK/letsencrypt
mkdir -p "$ETC/conf.d" "$ETC/snippets/gateway" "$ETC/sites-enabled" "$LE"

cat > "$ETC/nginx.conf" <<'CONF'
events {}
http {
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
CONF
cp "$ROOT/nginx/conf.d/"*.conf "$ETC/conf.d/"
cp "$ROOT/nginx/snippets/"*.conf "$ETC/snippets/gateway/"
cp "$ROOT/nginx/sites/tg.vashfindir.ru.conf" "$ETC/sites-enabled/tg-gateway"

DOMAINS=(tg.vashfindir.ru)
for domain in "${DOMAINS[@]}"; do
    mkdir -p "$LE/live/$domain"
    openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$domain" \
        -keyout "$LE/live/$domain/privkey.pem" -out "$LE/live/$domain/fullchain.pem" 2>/dev/null
done
# Файлы certbot — минимальные заглушки того же назначения.
echo 'ssl_session_cache shared:le_nginx_SSL:10m;' > "$LE/options-ssl-nginx.conf"
openssl dhparam -dsaparam -out "$LE/ssl-dhparams.pem" 2048 2>/dev/null

chmod -R a+rX "$WORK"
CMD=(nginx -t)
[ "${1:-}" = "--dump" ] && CMD=(nginx -T)
docker run --rm \
    -v "$ETC:/etc/nginx:ro" \
    -v "$LE:/etc/letsencrypt:ro" \
    "$NGINX_IMAGE" "${CMD[@]}"
