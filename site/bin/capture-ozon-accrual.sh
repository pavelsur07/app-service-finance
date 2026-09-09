#!/usr/bin/env bash
#
# Снимок финансовых начислений Ozon Seller API (accrual) в JSON-фикстуры.
#
# Назначение — получить реальные ответы Ozon по методам, которые заменили снятый
# 09.09.2026 /v3/finance/transaction/list, чтобы переписывать OzonAdapter по
# факту, а не по документации.
#
# Ничего не пишет в БД и не трогает приложение: только curl + файлы.
#
# Использование:
#   bin/capture-ozon-accrual.sh --date 2026-09-08          # спросит Client-Id и Api-Key
#   OZON_CLIENT_ID=... OZON_API_KEY=... bin/capture-ozon-accrual.sh --date 2026-09-08
#   bin/capture-ozon-accrual.sh --date 2026-09-08 --pages 1        # только первая страница
#   bin/capture-ozon-accrual.sh --date 2026-09-08 --no-postings    # без детализации отправлений
#   bin/capture-ozon-accrual.sh --date 2026-09-08 --pace 3          # реже стучать, если ловится 429
#
# Ключи можно передать через окружение (OZON_CLIENT_ID / OZON_API_KEY) —
# тогда они не попадут в history шелла.
#
# Снимок ложится в tests/Fixtures/Marketplace/Ozon/captured/ — каталог целиком
# под .gitignore. В репозиторий кладутся только вручную сокращённые и
# обезличенные фикстуры уровнем выше.

set -euo pipefail

BASE_URL="${OZON_BASE_URL:-https://api-seller.ozon.ru}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${SCRIPT_DIR}/../tests/Fixtures/Marketplace/Ozon/captured"
DATE=""
MAX_PAGES=0          # 0 = выгрузить всё
WITH_POSTINGS=1
POSTINGS_CHUNK=50    # сколько unit_number отдаём в /postings за раз
MAX_RETRIES=6        # попыток на один вызов при 429
RETRY_BASE_SECONDS=5 # первая пауза; дальше удвоение
MAX_BACKOFF_SECONDS=120  # потолок паузы, как DEFAULT_RETRY_AFTER_SECONDS в Ingestion
PACE_SECONDS=1       # пауза между вызовами разных эндпоинтов

usage() {
    sed -n '2,23p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --date)         DATE="$2"; shift 2 ;;
        --pages)        MAX_PAGES="$2"; shift 2 ;;
        --out)          OUT_DIR="$2"; shift 2 ;;
        --no-postings)  WITH_POSTINGS=0; shift ;;
        --pace)         PACE_SECONDS="$2"; shift 2 ;;
        --retries)      MAX_RETRIES="$2"; shift 2 ;;
        -h|--help)      usage 0 ;;
        *) echo "Неизвестный аргумент: $1" >&2; usage 1 ;;
    esac
done

command -v jq   >/dev/null || { echo "Нужен jq"   >&2; exit 1; }
command -v curl >/dev/null || { echo "Нужен curl" >&2; exit 1; }

[[ "$DATE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || {
    echo "--date обязателен и должен быть в формате YYYY-MM-DD" >&2; usage 1
}

if [[ -z "${OZON_CLIENT_ID:-}" ]]; then
    read -rp "Ozon Client-Id: " OZON_CLIENT_ID
fi
if [[ -z "${OZON_API_KEY:-}" ]]; then
    read -rsp "Ozon Api-Key: " OZON_API_KEY
    echo
fi
[[ -n "$OZON_CLIENT_ID" && -n "$OZON_API_KEY" ]] || { echo "Client-Id и Api-Key обязательны" >&2; exit 1; }

mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"

# POST <endpoint> <json-body> <output-file>
#
# Падает с телом ответа, если Ozon вернул не 200 — молчаливый пустой файл хуже
# ошибки. Именно так и был опознан снятый v3: code 9, obsolete method.
#
# 429 — исключение: это лимит запросов в секунду, а не отказ. Ключ у нас общий
# с работающим приложением (почасовой обход заказов, поллеры рекламы каждую
# минуту), поэтому попасть в лимит на ровном месте — норма. Ждём и повторяем,
# как это делает OzonAccrualClient в Ingestion: Retry-After, если пришёл, иначе
# удвоение с потолком.
ozon_post() {
    local endpoint="$1" body="$2" out="$3" code attempt=0 wait retry_after
    local hdr="${out}.headers"

    while :; do
        attempt=$((attempt + 1))
        code="$(curl -sS -o "$out" -D "$hdr" -w '%{http_code}' \
            -X POST "${BASE_URL}${endpoint}" \
            -H "Client-Id: ${OZON_CLIENT_ID}" \
            -H "Api-Key: ${OZON_API_KEY}" \
            -H 'Content-Type: application/json' \
            --max-time 120 \
            -d "$body")"

        if [[ "$code" == "200" ]]; then
            rm -f "$hdr"
            return 0
        fi

        if [[ "$code" == "429" && "$attempt" -lt "$MAX_RETRIES" ]]; then
            retry_after="$(awk 'tolower($0) ~ /^retry-after:/ {gsub(/[^0-9]/, "", $2); print $2; exit}' "$hdr" 2>/dev/null || true)"
            if [[ "$retry_after" =~ ^[0-9]+$ && "$retry_after" -gt 0 ]]; then
                wait="$retry_after"
            else
                wait=$((RETRY_BASE_SECONDS * 2 ** (attempt - 1)))
            fi
            [[ "$wait" -gt "$MAX_BACKOFF_SECONDS" ]] && wait="$MAX_BACKOFF_SECONDS"

            echo "  … ${endpoint} → HTTP 429 (лимит запросов), попытка ${attempt}/${MAX_RETRIES}, пауза ${wait}s" >&2
            sleep "$wait"
            continue
        fi

        echo "  ✗ ${endpoint} → HTTP ${code}" >&2
        head -c 2000 "$out" >&2; echo >&2
        rm -f "$out" "$hdr"
        exit 1
    done
}

# Пауза между вызовами: лимит у Ozon посекундный, а мы бьём три эндпоинта подряд.
pace() { sleep "$PACE_SECONDS"; }

# ── 1. /v1/finance/accrual/types — справочник услуг: type_id → имя ──
# Снимается первым: без него type_id в by-day не читаются глазами.
echo "1. POST /v1/finance/accrual/types"
ozon_post /v1/finance/accrual/types '{}' "${OUT_DIR}/accrual-types.json"
# Справочник отдаёт accrual_types[].id — именно id, а не type_id, которым на него
# ссылается by-day. Расхождение имён проверено на выгрузке 08.09.2026.
types_count="$(jq '[.accrual_types // [] | .[]] | length' "${OUT_DIR}/accrual-types.json")"
echo "   услуг в справочнике: ${types_count} → accrual-types.json"
echo
pace

# ── 2. /v1/finance/accrual/by-day — начисления за день, пагинация по last_id ──
echo "2. POST /v1/finance/accrual/by-day (${DATE})"
last_id=""
page=0
rows_total=0
: > "${OUT_DIR}/.unit-numbers"

while :; do
    page=$((page + 1))
    out="${OUT_DIR}/$(printf 'accrual-by-day.page-%02d.json' "$page")"

    if [[ -n "$last_id" ]]; then
        body="$(jq -nc --arg date "$DATE" --arg last_id "$last_id" '{date: $date, last_id: $last_id}')"
    else
        body="$(jq -nc --arg date "$DATE" '{date: $date}')"
    fi
    ozon_post /v1/finance/accrual/by-day "$body" "$out"

    count="$(jq '[.accruals // [] | .[]] | length' "$out")"
    last_id="$(jq -r '.last_id // ""' "$out")"
    rows_total=$((rows_total + count))

    # В /postings уходят только номера отправлений. unit_number несёт их лишь у
    # accrued_category = POSTING; у ITEM и NON_ITEM там идентификаторы другой
    # формы, а иногда пусто, и Ozon отвергает весь запрос целиком с
    # "value does not match regex pattern" — один чужой элемент рушит батч.
    jq -r '[.accruals[]? | select(.accrued_category == "POSTING") | .unit_number
            | select(. != null and . != "")
            | select(test("^[0-9]{1,32}-[0-9]{1,32}-[0-9]{1,32}$"))] | .[]' "$out" \
        >> "${OUT_DIR}/.unit-numbers" 2>/dev/null || true

    echo "   страница ${page}: ${count} начислений → $(basename "$out")"

    [[ "$count" -eq 0 || -z "$last_id" ]] && break
    [[ "$MAX_PAGES" -gt 0 && "$page" -ge "$MAX_PAGES" ]] && break
    pace
done
pace
echo

# ── 3. /v1/finance/accrual/postings — детализация отправлений ──
# Нужна, чтобы ответить, добирается ли отсюда quantity, которого нет в by-day.
postings_count=0
if [[ "$WITH_POSTINGS" -eq 1 ]]; then
    echo "3. POST /v1/finance/accrual/postings"
    sort -u "${OUT_DIR}/.unit-numbers" | head -n "$POSTINGS_CHUNK" > "${OUT_DIR}/.unit-numbers.chunk"
    postings_count="$(wc -l < "${OUT_DIR}/.unit-numbers.chunk" | tr -d ' ')"

    if [[ "$postings_count" -gt 0 ]]; then
        body="$(jq -Rn '{posting_numbers: [inputs]}' < "${OUT_DIR}/.unit-numbers.chunk")"
        ozon_post /v1/finance/accrual/postings "$body" "${OUT_DIR}/accrual-postings.json"
        echo "   отправлений запрошено: ${postings_count} → accrual-postings.json"
    else
        echo "   пропущено: за ${DATE} не нашлось ни одного unit_number"
    fi
    echo
fi

# ── Манифест: что именно и когда снято ──
jq -n \
    --arg captured_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --arg base_url "$BASE_URL" \
    --arg date "$DATE" \
    --argjson rows "$rows_total" \
    --argjson types "$types_count" \
    --argjson postings "$postings_count" \
    --argjson pages "$page" \
    '{captured_at: $captured_at, base_url: $base_url, business_date: $date,
      accrual_rows: $rows, accrual_pages: $pages, service_types: $types,
      postings_requested: $postings,
      endpoints: ["/v1/finance/accrual/types", "/v1/finance/accrual/by-day"]
                 + (if $postings > 0 then ["/v1/finance/accrual/postings"] else [] end)}' \
    > "${OUT_DIR}/_meta-accrual.json"

rm -f "${OUT_DIR}/.unit-numbers" "${OUT_DIR}/.unit-numbers.chunk"

echo "Готово. Файлы:"
ls -1sh "$OUT_DIR"
