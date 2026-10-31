#!/usr/bin/env bash
# Поведение шлюза (docs/tasks/ai/gateway/g1.md, B1–B12) в docker:
#   шлюз — конфиги репозитория (резолвер и CA подменены на тестовые);
#   мок-upstream под именами провайдеров, Telegram и app — эхо пути и заголовков;
#   клиенты с фиксированными IP: «app» (в allowlist) и «чужой».
set -uo pipefail

# shellcheck source=../scripts/layout.sh
source "$(dirname "$0")/../scripts/layout.sh"

NGINX_IMAGE=${NGINX_IMAGE:-nginx:1.28}
ID=gwtest-$$
NET=$ID
TOKEN=0123456789abcdef0123456789abcdef0123456789abcdef
NEXT=fedcba9876543210fedcba9876543210fedcba9876543210
WORK=$(mktemp -d)

cleanup() {
    docker rm -f "$ID-gw" "$ID-mock" "$ID-app" "$ID-stranger" >/dev/null 2>&1
    docker network rm "$NET" >/dev/null 2>&1
    rm -rf "$WORK"
}
trap cleanup EXIT

die() { echo "FAIL настройка: $*"; exit 1; }

# Сеть с подсетью от docker (фиксированная может пересечься с чужими);
# адреса клиентов — из её первых /24.
docker network create "$NET" >/dev/null || die "docker network create"
SUBNET=$(docker network inspect -f '{{(index .IPAM.Config 0).Subnet}}' "$NET")
PREFIX=${SUBNET%.*}
GW_IP=$PREFIX.10
APP_IP=$PREFIX.20
STRANGER_IP=$PREFIX.30
MOCK_IP=$PREFIX.40

# --- тестовый CA и сертификат мока на все имена upstream ---
UPSTREAMS=(api.openai.com api.deepseek.com api.moonshot.ai api.telegram.org app.vashfindir.ru)
mkdir -p "$WORK/ca" "$WORK/mock"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=gateway-test-ca" \
    -keyout "$WORK/ca/ca.key" -out "$WORK/ca/ca.pem" 2>/dev/null
SAN=$(printf 'DNS:%s,' "${UPSTREAMS[@]}")
openssl req -newkey rsa:2048 -nodes -subj "/CN=mock" -keyout "$WORK/mock/key.pem" \
    -out "$WORK/mock/req.csr" 2>/dev/null
openssl x509 -req -in "$WORK/mock/req.csr" -CA "$WORK/ca/ca.pem" -CAkey "$WORK/ca/ca.key" \
    -CAcreateserial -days 1 -out "$WORK/mock/cert.pem" \
    -extfile <(printf 'subjectAltName=%s' "${SAN%,}") 2>/dev/null

# Мок — njs: эхо метода, пути, заголовков и тела; /status/NNN в пути — ответ с этим статусом.
cat > "$WORK/mock/echo.js" <<'JS'
function handle(r) {
    var m = r.uri.match(/\/status\/(\d{3})/);
    var h = r.headersIn;
    var body = r.requestText || '';
    r.headersOut['openai-organization'] = 'org-secret';
    r.headersOut['openai-project'] = 'proj-secret';
    r.headersOut['Set-Cookie'] = '__cf_bm=abc';
    r.headersOut['Content-Type'] = 'application/json';
    r.return(m ? parseInt(m[1]) : 200, JSON.stringify({
        method: r.method, uri: r.variables.request_uri, host: h.host || '',
        auth: h.authorization || '',
        cookie: h.cookie || '', xff: h['x-forwarded-for'] || '', xri: h['x-real-ip'] || '',
        org: h['openai-organization'] || '', proj: h['openai-project'] || '',
        body_len: body.length, body: body.length < 1000 ? body : ''
    }));
}
export default { handle };
JS
cat > "$WORK/mock/nginx.conf" <<'CONF'
load_module modules/ngx_http_js_module.so;
events {}
http {
    js_import echo from /mock/echo.js;
    server {
        listen 443 ssl;
        ssl_certificate /mock/cert.pem;
        ssl_certificate_key /mock/key.pem;
        client_max_body_size 10m;
        client_body_buffer_size 10m;
        location / { js_content echo.handle; }
    }
}
CONF

# --- шлюз из файлов репозитория ---
AI_GATEWAY_TOKEN=$TOKEN AI_GATEWAY_TOKEN_NEXT=$NEXT AI_KIMI_API_KEY='' \
    gateway_test_secrets "$WORK/secrets.conf" || { echo "FAIL render-secrets"; exit 1; }
gateway_layout "$WORK/gw" "$WORK/secrets.conf"
# Тестовые подмены: резолвер docker и IP «app».
sed -i 's/resolver 127.0.0.53/resolver 127.0.0.11/' "$WORK/gw/nginx/sites-enabled/"*
sed -i "s/217\.198\.13\.171/$APP_IP/" "$WORK/gw/nginx/conf.d/"*.conf
chmod -R a+rX "$WORK"

ALIASES=()
for name in "${UPSTREAMS[@]}"; do ALIASES+=(--network-alias "$name"); done
docker run -d --name "$ID-mock" --network "$NET" --ip "$MOCK_IP" "${ALIASES[@]}" \
    -v "$WORK/mock:/mock:ro" -v "$WORK/mock/nginx.conf:/etc/nginx/nginx.conf:ro" \
    "$NGINX_IMAGE" >/dev/null || die "mock"
docker run -d --name "$ID-gw" --network "$NET" --ip "$GW_IP" \
    -v "$WORK/gw/nginx:/etc/nginx:ro" -v "$WORK/gw/letsencrypt:/etc/letsencrypt:ro" \
    -v "$WORK/ca/ca.pem:/etc/ssl/certs/ca-certificates.crt:ro" \
    "$NGINX_IMAGE" >/dev/null || die "gateway"
for c in app stranger; do
    ip=$APP_IP; [ "$c" = stranger ] && ip=$STRANGER_IP
    docker run -d --name "$ID-$c" --network "$NET" --ip "$ip" \
        --add-host "ai.vashfindir.ru:$GW_IP" --add-host "tg.vashfindir.ru:$GW_IP" \
        --entrypoint sleep "$NGINX_IMAGE" infinity >/dev/null || die "client $c"
done

for _ in $(seq 1 30); do
    docker exec "$ID-app" curl -sk -o /dev/null https://ai.vashfindir.ru/health && break
    sleep 0.5
done
for c in gw mock; do
    if [ "$(docker inspect -f '{{.State.Running}}' "$ID-$c")" != true ]; then
        docker logs "$ID-$c" 2>&1 | tail -20
        die "$c не запустился"
    fi
done

fail=0
# req <client> <curl args...> → $CODE, $BODY, $HEADERS
req() {
    local client=$1; shift
    local out
    out=$(docker exec "$ID-$client" sh -c 'curl -sk -m 10 -D /tmp/h -o /tmp/b -w "%{http_code}" "$@"; echo; cat /tmp/b; echo; echo ---; cat /tmp/h' sh "$@")
    CODE=$(printf '%s\n' "$out" | sed -n 1p)
    BODY=$(printf '%s\n' "$out" | sed -n '2,/^---$/p' | sed '$d')
    # shellcheck disable=SC2034 # читается в условиях check (eval)
    HEADERS=$(printf '%s\n' "$out" | sed -n '/^---$/,$p' | tr -d '\r')
}
check() {
    local name=$1 cond=$2
    if eval "$cond"; then echo "ok   $name"; else
        echo "FAIL $name"; echo "     code=$CODE body=$BODY" | head -c 600; echo; fail=1
    fi
}
AUTH=(-H "Authorization: Bearer $TOKEN")
AI=https://ai.vashfindir.ru

# B1
req stranger "$AI/health"; check "B1 /health 443 с любого IP" '[ "$CODE" = 200 ] && [ "$BODY" = alive ]'
req stranger http://ai.vashfindir.ru/health; check "B1 /health 80" '[ "$CODE" = 200 ]'
req stranger http://ai.vashfindir.ru/openai/v1/models; check "B1 80 → https" '[ "$CODE" = 301 ]'

# B2
req stranger "${AUTH[@]}" "$AI/openai/v1/models"; check "B2 чужой IP с верным токеном → 403" '[ "$CODE" = 403 ]'
req stranger "$AI/deepseek/v1/models"; check "B2 чужой IP без токена → 403, не 401" '[ "$CODE" = 403 ]'

# B3
req app "$AI/openai/v1/models"; check "B3 без токена → 401" '[ "$CODE" = 401 ] && [[ "$BODY" == *unauthorized* ]]'
req app -H "Authorization: Bearer wrong$TOKEN" "$AI/openai/v1/models"; check "B3 неверный токен → 401" '[ "$CODE" = 401 ]'
req app -H "Authorization: $TOKEN" "$AI/openai/v1/models"; check "B3 токен без Bearer → 401" '[ "$CODE" = 401 ]'
req app -H "Authorization: Bearer ${TOKEN^^}" "$AI/openai/v1/models"; check "B3 токен в другом регистре → 401" '[ "$CODE" = 401 ]'
req app -H "Authorization: Bearer ${NEXT^^}" "$AI/openai/v1/models"; check "B3 следующий токен в другом регистре → 401" '[ "$CODE" = 401 ]'
req app -H "Authorization: Bearer ${TOKEN}x" "$AI/openai/v1/models"; check "B3 токен с лишним символом → 401" '[ "$CODE" = 401 ]'

# B4 + B5 + B6
req app "${AUTH[@]}" -H "Cookie: s=1" -H "X-Forwarded-For: 1.2.3.4" -H "X-Real-IP: 1.2.3.4" \
    -H "OpenAI-Organization: org-client" -H "OpenAI-Project: proj-client" "$AI/openai/v1/models?limit=2"
check "B4 openai с токеном → 200" '[ "$CODE" = 200 ]'
check "B5 путь без префикса и с query" '[[ "$BODY" == *"\"uri\":\"/v1/models?limit=2\""* ]]'
check "B5 Host провайдера" '[[ "$BODY" == *"\"host\":\"api.openai.com\""* ]]'
check "B5 ключ провайдера вместо токена" '[[ "$BODY" == *"\"auth\":\"Bearer sk-test-openai\""* ]] && [[ "$BODY" != *"$TOKEN"* ]]'
check "B5 Cookie/XFF/X-Real-IP не уходят" '[[ "$BODY" == *"\"cookie\":\"\",\"xff\":\"\",\"xri\":\"\""* ]]'
check "B5 OpenAI-Organization/Project не уходят" '[[ "$BODY" == *"\"org\":\"\",\"proj\":\"\""* ]]'
check "B6 служебные заголовки ответа скрыты" '! grep -qiE "^(set-cookie|openai-organization|openai-project):" <<<"$HEADERS"'

req app -H "Authorization: Bearer $NEXT" "$AI/deepseek/v1/chat/completions"
check "B4 следующий токен (ротация) → 200" '[ "$CODE" = 200 ]'
check "B5 deepseek: Host и ключ" '[[ "$BODY" == *"\"host\":\"api.deepseek.com\""* ]] && [[ "$BODY" == *"Bearer sk-test-deepseek"* ]]'

req app "${AUTH[@]}" -X POST -H "Content-Type: application/json" \
    -d '{"model":"gpt-x","messages":[{"role":"user","content":"ok"}]}' "$AI/openai/v1/chat/completions"
check "B5 POST: метод и тело доходят без изменений" '[ "$CODE" = 200 ] && [[ "$BODY" == *"\"method\":\"POST\""* ]] && [[ "$BODY" == *"\"body\":\"{\\\"model\\\":\\\"gpt-x\\\",\\\"messages\\\":[{\\\"role\\\":\\\"user\\\",\\\"content\\\":\\\"ok\\\"}]}\""* ]]'
req app "${AUTH[@]}" "$AI/openai/status/400"
check "B6 ошибка провайдера: статус и тело как у провайдера" '[ "$CODE" = 400 ] && [[ "$BODY" == *"\"uri\":\"/status/400\""* ]]'
check "B6 служебные заголовки скрыты и в ответе с ошибкой" '! grep -qiE "^(set-cookie|openai-organization|openai-project):" <<<"$HEADERS"'
req app "${AUTH[@]}" "$AI/deepseek/status/503"
check "B6 503 провайдера не подменяется ответом шлюза" '[ "$CODE" = 503 ] && [[ "$BODY" == *"\"host\":\"api.deepseek.com\""* ]]'

# B7
req app "${AUTH[@]}" "$AI/kimi/v1/models"; check "B7 kimi без ключа → 503" '[ "$CODE" = 503 ] && [[ "$BODY" == *provider_not_configured* ]]'

# B8
docker exec "$ID-app" sh -c 'head -c 1100000 /dev/zero > /tmp/big'
req app "${AUTH[@]}" --data-binary @/tmp/big "$AI/openai/v1/chat/completions"; check "B8 тело > 1 МБ → 413" '[ "$CODE" = 413 ]'
docker exec "$ID-app" sh -c 'head -c 500000 /dev/zero > /tmp/ok'
req app "${AUTH[@]}" --data-binary @/tmp/ok "$AI/openai/v1/chat/completions"; check "B8 тело 0,5 МБ проходит" '[ "$CODE" = 200 ]'

# B10
req app "${AUTH[@]}" "$AI/unknown/v1/models"; check "B10 неизвестный путь → 404" '[ "$CODE" = 404 ]'
req app "${AUTH[@]}" "$AI/openai"; check "B10 /openai без слэша → 404" '[ "$CODE" = 404 ]'

# B12 — Telegram
req stranger https://tg.vashfindir.ru/bot-api/bot123:SECRET/getMe; check "B12 /bot-api/ не с app → 403" '[ "$CODE" = 403 ]'
req app https://tg.vashfindir.ru/bot-api/bot123:SECRET/getMe
check "B12 /bot-api/ с app → api.telegram.org" '[ "$CODE" = 200 ] && [[ "$BODY" == *"\"uri\":\"/bot123:SECRET/getMe\""* ]] && [[ "$BODY" == *api.telegram.org* ]]'
req stranger -X POST -d '{}' https://tg.vashfindir.ru/telegram/webhook
check "B12 вебхук → app" '[ "$CODE" = 200 ] && [[ "$BODY" == *app.vashfindir.ru* ]]'
req stranger https://tg.vashfindir.ru/health; check "B12 tg /health" '[ "$CODE" = 200 ]'

# B9 — последним: выбирает лимит deepseek
codes=$(docker exec "$ID-app" sh -c 'for i in $(seq 1 60); do curl -sk -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer '"$TOKEN"'" https://ai.vashfindir.ru/deepseek/v1/models; done' | sort | uniq -c | tr '\n' ' ')
CODE=$codes BODY=
check "B9 всплеск → 429 ($codes)" '[[ "$codes" == *" 429"* ]] && [[ "$codes" == *" 200"* ]]'

# B11 — журналы
docker exec "$ID-gw" sh -c 'cat /var/log/nginx/ai-gateway.access.log /var/log/nginx/tg-gateway.access.log' > "$WORK/access.log" 2>/dev/null
docker exec "$ID-gw" sh -c 'cat /var/log/nginx/ai-gateway.error.log /var/log/nginx/tg-gateway.error.log' > "$WORK/error.log" 2>/dev/null
CODE=- BODY=
check "B11 access log: успешный запрос с исходным путём и статусом" 'grep -q "\"path\":\"/openai/v1/models\",\"status\":200" "$WORK/access.log"'
check "B11 в журналах нет токена, ключей, query, токена бота" '! grep -qE "$TOKEN|$NEXT|sk-test-|limit=2|SECRET" "$WORK/access.log" "$WORK/error.log"'

exit $fail
