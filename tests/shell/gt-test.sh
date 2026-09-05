#!/usr/bin/env bash
# Поведение gt.sh как коммитируемый тест.
#
# Скрипт объявлял PROJECT и не использовал его: cmd_list ходил в
# /organizations/<slug>/issues/ и возвращал issues всей организации, включая чужой
# проект api_conwix. Из-за этого триаж по числу событий вводил в заблуждение —
# верхние строки выдачи принадлежали другому продукту.

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
script="$repo_root/gt.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

assert_contains() {
    grep -Fq -- "$2" "$1" || fail "$1 не содержит: $2"$'\n'"$(cat "$1")"
}

assert_not_contains() {
    if grep -Fq -- "$2" "$1"; then
        fail "$1 неожиданно содержит: $2"$'\n'"$(cat "$1")"
    fi
}

assert_matches() {
    grep -Eq -- "$2" "$1" || fail "$1 не совпал с /$2/"$'\n'"$(cat "$1")"
}

# curl-заглушка: пишет запрошенный URL в лог и отдаёт минимальный валидный ответ
# в том же формате, что ждёт gt_get — тело, перевод строки, HTTP-код.
make_fake_curl() {
    local bin_dir=$1
    mkdir -p "$bin_dir"
    cat > "$bin_dir/curl" <<'CURLEOF'
#!/usr/bin/env bash
set -euo pipefail
url=""
for arg in "$@"; do url="$arg"; done
printf '%s\n' "$url" >> "$CURL_TEST_LOG"
case "$url" in
    */organizations/)
        printf '[{"slug":"test-org","name":"Test"}]\n200' ;;
    *issues*)
        printf '%s\n200' "$CURL_ISSUES_FIXTURE" ;;
    *)
        printf '[]\n200' ;;
esac
CURLEOF
    chmod +x "$bin_dir/curl"
}

bin_dir="$test_root/bin"
make_fake_curl "$bin_dir"

export CURL_TEST_LOG="$test_root/curl.log"
: > "$CURL_TEST_LOG"
export PATH="$bin_dir:$PATH"
export GLITCHTIP_TOKEN="test-token"

output="$test_root/out.txt"

export CURL_ISSUES_FIXTURE='[{"id":1,"count":2,"title":"Boom","lastSeen":"2026-09-05T03:47:10Z"}]'
"$script" list 5 > "$output" 2>&1 || fail "gt.sh list завершился с ошибкой:"$'\n'"$(cat "$output")"

assert_contains "$CURL_TEST_LOG" "/projects/test-org/app_vashfindirru/issues/"
assert_not_contains "$CURL_TEST_LOG" "/organizations/test-org/issues/"
assert_contains "$CURL_TEST_LOG" "query=is:unresolved"
assert_contains "$CURL_TEST_LOG" "limit=5"

# Свежесть обязана быть в выдаче: без неё приоритизация идёт по накопленному числу
# событий, и давно потухший issue выглядит важнее вчерашнего.
assert_contains "$output" "2026-09-05"

# Ответ без lastSeen не должен ронять вывод: у issue, не попавшего в индекс поиска,
# поля может не быть, и падение читалки на этом месте оставило бы без выдачи всё.
for fixture in \
    '[{"id":2,"count":1,"title":"NoLastSeen"}]' \
    '[{"id":3,"count":1,"title":"NullLastSeen","lastSeen":null}]'
do
    : > "$CURL_TEST_LOG"
    export CURL_ISSUES_FIXTURE="$fixture"
    "$script" list 5 > "$output" 2>&1 \
        || fail "gt.sh list упал на ответе без lastSeen ($fixture):"$'\n'"$(cat "$output")"
    # Именно прочерк в колонке LAST, а не любой дефис в выводе.
    assert_matches "$output" '^[0-9]+ +[0-9]+ +- +[A-Za-z]+LastSeen$'
done

printf 'OK: gt.sh list запрашивает issues проекта, а не всей организации\n'
