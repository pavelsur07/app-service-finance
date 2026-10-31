#!/usr/bin/env bash
# Раскладка конфигов шлюза как на хосте (Ubuntu) для проверок в контейнере.
# Источник: scripts/nginx-test.sh, tests/*.sh.
#   gateway_layout <work> <secrets.conf>
#     → <work>/nginx (как /etc/nginx), <work>/letsencrypt (самоподписанные заглушки)

GATEWAY_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
GATEWAY_DOMAINS=(tg.vashfindir.ru ai.vashfindir.ru)

gateway_layout() {
    local work=$1 secrets=$2 etc le domain
    etc=$work/nginx
    le=$work/letsencrypt
    mkdir -p "$etc/conf.d" "$etc/snippets/gateway" "$etc/sites-enabled" "$le"

    cat > "$etc/nginx.conf" <<'CONF'
events {}
http {
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
CONF
    cp "$GATEWAY_ROOT/nginx/conf.d/"*.conf "$etc/conf.d/"
    cp "$GATEWAY_ROOT/nginx/snippets/"*.conf "$etc/snippets/gateway/"
    cp "$GATEWAY_ROOT/nginx/sites/tg.vashfindir.ru.conf" "$etc/sites-enabled/tg-gateway"
    cp "$GATEWAY_ROOT/nginx/sites/ai.vashfindir.ru.conf" "$etc/sites-enabled/ai-gateway"
    cp "$secrets" "$etc/gateway-secrets.conf"

    for domain in "${GATEWAY_DOMAINS[@]}"; do
        mkdir -p "$le/live/$domain"
        openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$domain" \
            -keyout "$le/live/$domain/privkey.pem" -out "$le/live/$domain/fullchain.pem" 2>/dev/null
    done
    # Файлы certbot — минимальные заглушки того же назначения.
    echo 'ssl_session_cache shared:le_nginx_SSL:10m;' > "$le/options-ssl-nginx.conf"
    openssl dhparam -dsaparam -out "$le/ssl-dhparams.pem" 2048 2>/dev/null
    chmod -R a+rX "$work"
}

# Тестовые значения секретов (формат как у GitHub Secrets).
gateway_test_secrets() {
    local out=$1
    AI_GATEWAY_TOKEN=${AI_GATEWAY_TOKEN:-0123456789abcdef0123456789abcdef0123456789abcdef} \
    AI_GATEWAY_TOKEN_NEXT=${AI_GATEWAY_TOKEN_NEXT:-} \
    AI_OPENAI_API_KEY=${AI_OPENAI_API_KEY:-sk-test-openai} \
    AI_DEEPSEEK_API_KEY=${AI_DEEPSEEK_API_KEY:-sk-test-deepseek} \
    AI_KIMI_API_KEY=${AI_KIMI_API_KEY:-} \
        "$GATEWAY_ROOT/scripts/render-secrets.sh" "$out"
}
