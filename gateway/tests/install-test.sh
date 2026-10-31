#!/usr/bin/env bash
# Тест scripts/install.sh в контейнере с раскладкой хоста: прежний сайт Telegram
# и чужой mybots. Сценарии: битый конфиг, сбой установки, провал проверки —
# прежний набор байт-в-байт и без бэкапов; успех; повтор; права секретов.
set -uo pipefail

# shellcheck source=../scripts/layout.sh
source "$(dirname "$0")/../scripts/layout.sh"

NGINX_IMAGE=${NGINX_IMAGE:-nginx:1.28}
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Сертификаты — из той же раскладки; секреты — тестовые.
gateway_test_secrets "$WORK/secrets.conf" || { echo "FAIL render-secrets"; exit 1; }
gateway_layout "$WORK/layout" "$WORK/secrets.conf"
mkdir -p "$WORK/stage"
cp -r "$GATEWAY_ROOT/nginx" "$GATEWAY_ROOT/scripts" "$WORK/stage/"
cp "$WORK/secrets.conf" "$WORK/stage/gateway-secrets.conf"
chmod -R a+rX "$WORK"

cat > "$WORK/inner.sh" <<'INNER'
set -u
export CURL_CA_BUNDLE=/le/ca-bundle.pem
export GATEWAY_SKIP_PROVIDER_CHECK=1
cat > /etc/nginx/nginx.conf <<'C'
events {}
http { include /etc/nginx/conf.d/*.conf; include /etc/nginx/sites-enabled/*; }
C
rm -f /etc/nginx/conf.d/default.conf
mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/letsencrypt
cp -r /le/. /etc/letsencrypt/
# Без паузы после reload, как systemctl на хосте: проверки должны пережить гонку
# со старыми worker'ами (деплой G1 31.10 откатился именно на ней).
printf '#!/bin/sh\nnginx -s reload\n' > /usr/local/bin/systemctl; chmod +x /usr/local/bin/systemctl
# Прежнее состояние: старый сайт Telegram и чужой mybots.
cat > /etc/nginx/sites-available/tg-gateway <<'C'
server { listen 80; server_name tg.vashfindir.ru; location = /health { return 200 'old'; } }
C
ln -s /etc/nginx/sites-available/tg-gateway /etc/nginx/sites-enabled/tg-gateway
echo 'server { listen 8081; return 200; }' > /etc/nginx/sites-available/mybots
ln -s /etc/nginx/sites-available/mybots /etc/nginx/sites-enabled/mybots
nginx; sleep 1

fail=0
sum() { find /etc/nginx -type f -o -type l | sort | xargs md5sum 2>/dev/null | md5sum; }
backups() { find /tmp -maxdepth 1 -name 'tmp.*' | wc -l; }
expect_rollback() {
    local name=$1 dir=$2 before=$3 rc
    bash /stage/scripts/install.sh "$dir/nginx" "$dir/gateway-secrets.conf" >/tmp/log 2>&1; rc=$?
    if [ "$rc" != 0 ] && [ "$(sum)" = "$before" ] && [ "$(backups)" = 0 ]; then echo "ok   $name"
    else echo "FAIL $name (rc=$rc, набор $( [ "$(sum)" = "$before" ] && echo прежний || echo ИЗМЕНЁН ), бэкапов $(backups))"; tail -20 /tmp/log; fail=1; fi
}

BEFORE=$(sum)
rm -rf /bad; cp -r /stage /bad; echo 'garbage;' >> /bad/nginx/sites/ai.vashfindir.ru.conf
expect_rollback "битый сайт ai → откат" /bad "$BEFORE"
rm -rf /bad; cp -r /stage /bad; rm /bad/nginx/snippets/*.conf
expect_rollback "сбой установки → откат" /bad "$BEFORE"
rm -rf /bad; cp -r /stage /bad; sed -i 's|include /etc/nginx/snippets/gateway/ai-gate.conf;||' /bad/nginx/sites/ai.vashfindir.ru.conf
expect_rollback "провал проверки (ai открыт не только app) → откат" /bad "$BEFORE"
rm -rf /bad; cp -r /stage /bad; rm /bad/gateway-secrets.conf
expect_rollback "нет файла секретов → откат" /bad "$BEFORE"

if bash /stage/scripts/install.sh /stage/nginx /stage/gateway-secrets.conf >/tmp/log 2>&1; then echo "ok   установка"
else echo "FAIL установка"; cat /tmp/log; fail=1; fi
grep -E 'ожидается' /tmp/log | sed 's/^/     /'
p=$(stat -c '%U:%G %a' /etc/nginx/gateway-secrets.conf 2>/dev/null)
if [ "$p" = "root:root 600" ]; then echo "ok   секреты root:root 600"; else echo "FAIL секреты: $p"; fail=1; fi
if [ -L /etc/nginx/sites-enabled/ai-gateway ] && [ -L /etc/nginx/sites-enabled/mybots ]; then echo "ok   сайты ai и mybots включены"; else echo "FAIL sites-enabled"; ls -la /etc/nginx/sites-enabled; fail=1; fi
if grep -qE 'sk-test-|0123456789abcdef' /tmp/log; then echo "FAIL секреты в выводе установки"; fail=1; else echo "ok   секретов в выводе нет"; fi
if [ "$(backups)" = 0 ]; then echo "ok   бэкап удалён"; else echo "FAIL бэкап остался"; fail=1; fi

if bash /stage/scripts/install.sh /stage/nginx /stage/gateway-secrets.conf >/tmp/log 2>&1; then echo "ok   повтор"; else echo "FAIL повтор"; cat /tmp/log; fail=1; fi

# Проверка 1 с фиктивными ключами: провайдер ответит не 200 (или недоступен) —
# выход 3, конфиг остаётся (без отката), ключ не печатается, Kimi без ключа пропущен.
AFTER=$(sum)
GATEWAY_SKIP_PROVIDER_CHECK=0 bash /stage/scripts/install.sh /stage/nginx /stage/gateway-secrets.conf >/tmp/log 2>&1; rc=$?
grep -E '^(openai|deepseek|kimi):' /tmp/log | sed 's/^/     /'
if [ "$rc" = 3 ] && [ "$(sum)" = "$AFTER" ] && [ "$(backups)" = 0 ] \
    && grep -q '^openai: [0-9]' /tmp/log && grep -q '^deepseek: [0-9]' /tmp/log \
    && grep -q '^kimi: not configured' /tmp/log && ! grep -q 'sk-test-' /tmp/log; then
    echo "ok   проверка 1: выход 3 без отката, ключи разобраны и не напечатаны"
else echo "FAIL проверка 1 (rc=$rc)"; cat /tmp/log; fail=1; fi
exit $fail
INNER

# Клиент curl на хосте проверяет сертификаты заглушек — собираем их в один bundle.
cat "$WORK/layout/letsencrypt/live/"*/fullchain.pem > "$WORK/layout/letsencrypt/ca-bundle.pem"
docker run --rm \
    -v "$WORK/stage:/stage:ro" -v "$WORK/layout/letsencrypt:/le:ro" -v "$WORK/inner.sh:/inner.sh:ro" \
    --entrypoint bash "$NGINX_IMAGE" /inner.sh
