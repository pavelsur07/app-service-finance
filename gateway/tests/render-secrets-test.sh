#!/usr/bin/env bash
# Тест scripts/render-secrets.sh: обязательный токен, алфавит, пустой ключ, NEXT, права.
set -uo pipefail

RENDER=$(cd "$(dirname "$0")/.." && pwd)/scripts/render-secrets.sh
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
TOKEN=0123456789abcdef0123456789abcdef0123456789abcdef
fail=0

render() {
    env -i PATH="$PATH" "$@" "$RENDER" "$WORK/out.conf" >"$WORK/log" 2>&1
}
expect_ok() {
    local name=$1; shift
    rm -f "$WORK/out.conf"
    if render "$@"; then echo "ok   $name"; else echo "FAIL $name (ожидался успех)"; cat "$WORK/log"; fail=1; fi
}
expect_fail() {
    local name=$1; shift
    rm -f "$WORK/out.conf"
    if render "$@"; then echo "FAIL $name (ожидался отказ)"; fail=1
    elif [ -e "$WORK/out.conf" ]; then echo "FAIL $name (файл остался после отказа)"; fail=1
    elif grep -qF -- "${SECRET:-__none__}" "$WORK/log"; then echo "FAIL $name (значение в выводе)"; fail=1
    else echo "ok   $name"; fi
}
has() {
    local name=$1 pattern=$2
    if grep -qF -- "$pattern" "$WORK/out.conf"; then echo "ok   $name"; else echo "FAIL $name: нет «$pattern»"; fail=1; fi
}

expect_ok "все значения" AI_GATEWAY_TOKEN=$TOKEN AI_GATEWAY_TOKEN_NEXT=${TOKEN}ff \
    AI_OPENAI_API_KEY=sk-proj-Ab_1.2~3 AI_DEEPSEEK_API_KEY=sk-ds AI_KIMI_API_KEY=sk-kimi
has "токен" "\"Bearer $TOKEN\" 1;"
has "следующий токен" "\"Bearer ${TOKEN}ff\" 1;"
has "ключ openai" 'map $host $ai_openai_key { default "sk-proj-Ab_1.2~3"; }'
has "ключ deepseek" 'map $host $ai_deepseek_key { default "sk-ds"; }'
has "ключ kimi" 'map $host $ai_kimi_key { default "sk-kimi"; }'
has "по умолчанию не пускать" "default 0;"
perm=$(stat -c %a "$WORK/out.conf")
if [ "$perm" = 600 ]; then echo "ok   права 600"; else echo "FAIL права $perm"; fail=1; fi

expect_ok "без kimi и NEXT" AI_GATEWAY_TOKEN=$TOKEN AI_OPENAI_API_KEY=sk-o AI_DEEPSEEK_API_KEY=sk-d
has "пустой ключ kimi" 'map $host $ai_kimi_key { default ""; }'
if grep -q 'Bearer "' "$WORK/out.conf" || grep -c 'Bearer' "$WORK/out.conf" | grep -qv '^1$'; then
    echo "FAIL пустой NEXT не должен давать строку токена"; fail=1
else echo "ok   пустой NEXT пропущен"; fi

expect_fail "нет токена" AI_OPENAI_API_KEY=sk-o
expect_fail "короткий токен" AI_GATEWAY_TOKEN=abc
SECRET='sk-bad"; return 200' expect_fail "кавычка в ключе" AI_GATEWAY_TOKEN=$TOKEN 'AI_OPENAI_API_KEY=sk-bad"; return 200'
SECRET='sk$host' expect_fail "доллар в ключе" AI_GATEWAY_TOKEN=$TOKEN 'AI_DEEPSEEK_API_KEY=sk$host'
expect_fail "пробел в токене" "AI_GATEWAY_TOKEN=${TOKEN} x"
expect_fail "перевод строки в NEXT" AI_GATEWAY_TOKEN=$TOKEN "AI_GATEWAY_TOKEN_NEXT=${TOKEN}
x"

exit $fail
