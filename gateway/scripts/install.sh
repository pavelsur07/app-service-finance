#!/usr/bin/env bash
# Установка конфигов шлюза на хосте. Запускает .github/workflows/deploy-gateway.yml:
#   bash install.sh <каталог с распакованным gateway/nginx> <gateway-secrets.conf>
# Все управляемые файлы ставятся набором: при любом неуспешном выходе после
# бэкапа (сбой установки, nginx -t, reload, проверок хоста) возвращается прежний
# набор целиком. Проверка ключей провайдеров (проверка 1) идёт после — её провал
# делает выход ненулевым, но не откатывает: откат не чинит ключ, а Telegram не
# должен зависеть от провайдера. GATEWAY_SKIP_PROVIDER_CHECK=1 — без неё (тесты).
set -euo pipefail

SRC=${1:?usage: install.sh <gateway/nginx dir> <secrets file>}
SECRETS=${2:?usage: install.sh <gateway/nginx dir> <secrets file>}

# Пути относительно / — так их удобно класть в один tar бэкапа.
MANAGED=(
    etc/nginx/conf.d/gateway-log-format.conf
    etc/nginx/conf.d/gateway-app.conf
    etc/nginx/gateway-secrets.conf
    etc/nginx/snippets/gateway
    etc/nginx/sites-available/tg-gateway
    etc/nginx/sites-enabled/tg-gateway
    etc/nginx/sites-available/ai-gateway
    etc/nginx/sites-enabled/ai-gateway
)
CERTS=(tg.vashfindir.ru ai.vashfindir.ru)

for domain in "${CERTS[@]}"; do
    if [ ! -f "/etc/letsencrypt/live/$domain/fullchain.pem" ]; then
        echo "❌ Нет сертификата $domain: certbot certonly --nginx -d $domain"
        exit 1
    fi
done

BACKUP=$(mktemp)
EXISTING=()
for p in "${MANAGED[@]}"; do
    if [ -e "/$p" ] || [ -L "/$p" ]; then EXISTING+=("$p"); fi
done
if [ ${#EXISTING[@]} -gt 0 ]; then
    tar -C / -cpf "$BACKUP" "${EXISTING[@]}"
fi

DONE=0
on_exit() {
    local rc=$?
    trap - EXIT
    if [ "$DONE" = 1 ]; then rm -f "$BACKUP"; exit "$rc"; fi
    set +e
    echo "↩️  Возвращаю прежний набор файлов шлюза"
    local clean=1
    for p in "${MANAGED[@]}"; do rm -rf "/${p:?}" || clean=0; done
    # Неудалённый новый файл tar не перекроет — набор был бы смешанным.
    if [ "$clean" = 1 ] && { [ ${#EXISTING[@]} -eq 0 ] || tar -C / -xpf "$BACKUP"; }; then
        if nginx -t && systemctl reload nginx; then
            rm -f "$BACKUP"
            exit "$rc"
        fi
    fi
    echo "❌ Откат не завершён — бэкап сохранён: $BACKUP (tar -C / -xpf)"
    exit "$rc"
}
trap on_exit EXIT

install -m 0644 "$SRC/conf.d/gateway-log-format.conf" /etc/nginx/conf.d/gateway-log-format.conf
install -m 0644 "$SRC/conf.d/gateway-app.conf" /etc/nginx/conf.d/gateway-app.conf
install -m 0600 -o root -g root "$SECRETS" /etc/nginx/gateway-secrets.conf
rm -rf /etc/nginx/snippets/gateway
install -d -m 0755 /etc/nginx/snippets/gateway
install -m 0644 "$SRC"/snippets/*.conf /etc/nginx/snippets/gateway/
install -m 0644 "$SRC/sites/tg.vashfindir.ru.conf" /etc/nginx/sites-available/tg-gateway
ln -sfn /etc/nginx/sites-available/tg-gateway /etc/nginx/sites-enabled/tg-gateway
install -m 0644 "$SRC/sites/ai.vashfindir.ru.conf" /etc/nginx/sites-available/ai-gateway
ln -sfn /etc/nginx/sites-available/ai-gateway /etc/nginx/sites-enabled/ai-gateway

if ! nginx -t; then
    echo "❌ nginx -t не прошёл"
    exit 1
fi
systemctl reload nginx

check() {
    local name=$1 expected=$2 code
    shift 2
    code=$(curl -s -m 10 -o /dev/null -w '%{http_code}' "$@" || true)
    echo "$name: $code (ожидается $expected)"
    [ "$code" = "$expected" ]
}

echo "🔍 Проверки"
TG=(--resolve tg.vashfindir.ru:443:127.0.0.1 --resolve tg.vashfindir.ru:80:127.0.0.1)
ok=1
check "tg /health 443" 200 "${TG[@]}" https://tg.vashfindir.ru/health || ok=0
check "tg /health 80" 200 "${TG[@]}" http://tg.vashfindir.ru/health || ok=0
# Хост шлюза не в allowlist: 403 значит, что app-only на месте.
check "tg /bot-api/ не с app" 403 "${TG[@]}" https://tg.vashfindir.ru/bot-api/getMe || ok=0
check "tg неизвестный путь" 404 "${TG[@]}" https://tg.vashfindir.ru/unknown || ok=0
AI=(--resolve ai.vashfindir.ru:443:127.0.0.1 --resolve ai.vashfindir.ru:80:127.0.0.1)
check "ai /health 443" 200 "${AI[@]}" https://ai.vashfindir.ru/health || ok=0
check "ai /health 80" 200 "${AI[@]}" http://ai.vashfindir.ru/health || ok=0
check "ai провайдер не с app" 403 "${AI[@]}" https://ai.vashfindir.ru/openai/v1/models || ok=0
check "ai неизвестный путь" 404 "${AI[@]}" https://ai.vashfindir.ru/unknown || ok=0

if [ "$ok" != 1 ]; then
    echo "❌ Проверки шлюза не прошли"
    exit 1
fi
DONE=1

# Доступность upstream — информативно: их сбой не повод откатывать конфиг шлюза.
curl -s -m 10 -o /dev/null -w "upstream app.vashfindir.ru: %{http_code}\n" https://app.vashfindir.ru/telegram/webhook || true
curl -s -m 10 -o /dev/null -w "upstream api.telegram.org: %{http_code}\n" https://api.telegram.org/ || true

# Проверка 1: ключ каждого настроенного провайдера действителен и провайдер
# доступен с хоста. Ключ — в заголовке через stdin curl, не в аргументах процесса.
provider_key() {
    sed -n "s/^map \$host \$ai_$1_key { default \"\(.*\)\"; }$/\1/p" /etc/nginx/gateway-secrets.conf
}
providers_ok=1
if [ "${GATEWAY_SKIP_PROVIDER_CHECK:-0}" != 1 ]; then
    echo "🔍 Проверка 1: ключи провайдеров"
    for entry in openai=https://api.openai.com/v1/models \
                 deepseek=https://api.deepseek.com/models \
                 kimi=https://api.moonshot.ai/v1/models; do
        name=${entry%%=*}
        url=${entry#*=}
        key=$(provider_key "$name")
        if [ -z "$key" ]; then
            echo "$name: not configured"
            continue
        fi
        code=$(printf 'header = "Authorization: Bearer %s"\n' "$key" \
            | curl -s -m 15 -o /dev/null -w '%{http_code}' -K - "$url" || true)
        echo "$name: $code (ожидается 200)"
        [ "$code" = 200 ] || providers_ok=0
    done
fi

if [ "$providers_ok" != 1 ]; then
    echo "❌ Конфиг шлюза установлен, но ключ провайдера не прошёл проверку 1"
    exit 3
fi
echo "✅ gateway deploy completed"
