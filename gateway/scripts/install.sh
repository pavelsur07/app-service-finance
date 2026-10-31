#!/usr/bin/env bash
# Установка конфигов шлюза на хосте. Запускает .github/workflows/deploy-gateway.yml:
#   bash install.sh <каталог с распакованным gateway/nginx>
# Все управляемые файлы ставятся набором: при любом неуспешном выходе после
# бэкапа (сбой установки, nginx -t, reload, проверок) возвращается прежний набор целиком.
set -euo pipefail

SRC=${1:?usage: install.sh <gateway/nginx dir>}

# Пути относительно / — так их удобно класть в один tar бэкапа.
MANAGED=(
    etc/nginx/conf.d/gateway-log-format.conf
    etc/nginx/snippets/gateway
    etc/nginx/sites-available/tg-gateway
    etc/nginx/sites-enabled/tg-gateway
)
CERTS=(tg.vashfindir.ru)

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
    if [ "$DONE" = 1 ]; then rm -f "$BACKUP"; exit 0; fi
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
rm -rf /etc/nginx/snippets/gateway
install -d -m 0755 /etc/nginx/snippets/gateway
install -m 0644 "$SRC"/snippets/*.conf /etc/nginx/snippets/gateway/
install -m 0644 "$SRC/sites/tg.vashfindir.ru.conf" /etc/nginx/sites-available/tg-gateway
ln -sfn /etc/nginx/sites-available/tg-gateway /etc/nginx/sites-enabled/tg-gateway

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

if [ "$ok" != 1 ]; then
    echo "❌ Проверки шлюза не прошли"
    exit 1
fi
DONE=1

# Доступность upstream — информативно: их сбой не повод откатывать конфиг шлюза.
curl -s -m 10 -o /dev/null -w "upstream app.vashfindir.ru: %{http_code}\n" https://app.vashfindir.ru/telegram/webhook || true
curl -s -m 10 -o /dev/null -w "upstream api.telegram.org: %{http_code}\n" https://api.telegram.org/ || true

echo "✅ gateway deploy completed"
