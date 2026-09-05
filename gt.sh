#!/usr/bin/env bash
# gt.sh — read-only GlitchTip incident reader. GET only, never mutates.
set -euo pipefail

BASE="https://glitchtip.staging.vashfindir.ru/api/0"
PROJECT="app_vashfindirru"

for bin in curl jq; do
    command -v "$bin" >/dev/null 2>&1 || { echo "error: '$bin' not found in PATH" >&2; exit 1; }
done
[ -n "${GLITCHTIP_TOKEN:-}" ] || { echo "error: GLITCHTIP_TOKEN is empty — export it first" >&2; exit 1; }

# GET <path> -> stdout body; exits non-zero and prints code+body on non-200.
gt_get() {
    local body code
    body=$(curl -s -w '\n%{http_code}' -H "Authorization: Bearer $GLITCHTIP_TOKEN" "$BASE$1")
    code=${body##*$'\n'}
    body=${body%$'\n'*}
    if [ "$code" != "200" ]; then
        echo "error: API returned $code for $1" >&2
        echo "$body" >&2
        exit 1
    fi
    printf '%s' "$body"
}

org_slug() { gt_get "/organizations/" | jq -r '.[0].slug'; }

# Выдача ограничена проектом $PROJECT. Организационный эндпоинт
# /organizations/<slug>/issues/ отдаёт issues всех проектов сразу, включая чужой
# api_conwix, и триаж по числу событий начинает врать: верхние строки принадлежат
# другому продукту. lastSeen выводится по той же причине — без свежести давно
# потухший issue выглядит важнее вчерашнего.
cmd_list() {
    local limit=${1:-10} slug
    slug=$(org_slug)
    gt_get "/projects/$slug/$PROJECT/issues/?query=is:unresolved&limit=$limit" \
        | jq -r '["ID","EVENTS","LAST","TITLE"], (.[] | [.id, .count, (.lastSeen // "-" | .[0:16]), .title]) | @tsv' \
        | column -t -s $'\t'
}

cmd_show() { gt_get "/issues/$1/events/latest/" | jq .; }

cmd_brief() {
    gt_get "/issues/$1/events/latest/" | jq '{
        title,
        culprit,
        tags: (.tags // [] | map(select(.key=="release" or .key=="server_name")) | from_entries),
        context: (.context // {}),
        exception: (first((.entries // [])[] | select(.type=="exception") | .data) // null)
    }'
}

case "${1:-}" in
    list)  cmd_list "${2:-}";;
    show)  [ -n "${2:-}" ] || { echo "usage: gt.sh show <issue_id>" >&2; exit 1; }; cmd_show "$2";;
    brief) [ -n "${2:-}" ] || { echo "usage: gt.sh brief <issue_id>" >&2; exit 1; }; cmd_brief "$2";;
    *)     echo "usage: gt.sh {list [limit] | show <id> | brief <id>}" >&2; exit 1;;
esac
