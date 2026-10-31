#!/usr/bin/env bash
# Собирает /etc/nginx/gateway-secrets.conf из GitHub Secrets (переменные окружения):
#   AI_GATEWAY_TOKEN       обязателен, ≥ 32 символов — токен клиентов шлюза ИИ
#   AI_GATEWAY_TOKEN_NEXT  необязателен — второй принимаемый токен на время ротации
#   AI_OPENAI_API_KEY, AI_DEEPSEEK_API_KEY, AI_KIMI_API_KEY — пустой = провайдер не настроен (503)
# Значения только из A-Za-z0-9._~- — в строку конфига nginx не попадут кавычки, $ и ;.
# Значения не печатаются. Файл создаётся с правами 0600.
# Токен — регулярка `~` (с учётом регистра, целиком): строковые ключи map nginx
# сравнивает без учёта регистра.
#   render-secrets.sh <out>
set -euo pipefail

OUT=${1:?usage: render-secrets.sh <out>}
ALPHABET='^[A-Za-z0-9._~-]+$'

valid() {
    local name=$1 value=$2
    if [ -n "$value" ] && ! [[ "$value" =~ $ALPHABET ]]; then
        echo "❌ $name: недопустимые символы (разрешены A-Za-z0-9._~-)" >&2
        exit 1
    fi
}

TOKEN=${AI_GATEWAY_TOKEN:-}
NEXT=${AI_GATEWAY_TOKEN_NEXT:-}
if [ ${#TOKEN} -lt 32 ]; then
    echo "❌ AI_GATEWAY_TOKEN: не задан или короче 32 символов (openssl rand -hex 24)" >&2
    exit 1
fi
if [ -n "$NEXT" ] && [ ${#NEXT} -lt 32 ]; then
    echo "❌ AI_GATEWAY_TOKEN_NEXT: короче 32 символов" >&2
    exit 1
fi
valid AI_GATEWAY_TOKEN "$TOKEN"
valid AI_GATEWAY_TOKEN_NEXT "$NEXT"
for name in AI_OPENAI_API_KEY AI_DEEPSEEK_API_KEY AI_KIMI_API_KEY; do
    valid "$name" "${!name:-}"
done

# Из алфавита в регулярке особая только точка.
regex_literal() { printf '%s' "${1//./\\.}"; }

umask 077
tmp=$(mktemp "$OUT.XXXXXX")
trap 'rm -f "$tmp"' EXIT
{
    echo "# Собран deploy-gateway.yml (scripts/render-secrets.sh) — не править, не коммитить."
    echo 'map $http_authorization $ai_client_ok {'
    echo '    default 0;'
    echo "    \"~^Bearer $(regex_literal "$TOKEN")\$\" 1;"
    [ -n "$NEXT" ] && echo "    \"~^Bearer $(regex_literal "$NEXT")\$\" 1;"
    echo '}'
    echo "map \$host \$ai_openai_key { default \"${AI_OPENAI_API_KEY:-}\"; }"
    echo "map \$host \$ai_deepseek_key { default \"${AI_DEEPSEEK_API_KEY:-}\"; }"
    echo "map \$host \$ai_kimi_key { default \"${AI_KIMI_API_KEY:-}\"; }"
} > "$tmp"
mv "$tmp" "$OUT"
trap - EXIT
