#!/usr/bin/env bash
#
# Снимок каталога товаров Ozon Seller API в JSON-фикстуры.
#
# Назначение — получить реальные ответы Ozon (реальные поля, реальные типы),
# чтобы писать нормализацию и тесты по факту, а не по документации.
#
# Ничего не пишет в БД и не трогает приложение: только curl + файлы.
#
# Использование:
#   bin/capture-ozon-listings.sh                       # спросит Client-Id и Api-Key
#   OZON_CLIENT_ID=... OZON_API_KEY=... bin/capture-ozon-listings.sh --pages 1 --limit 50
#   bin/capture-ozon-listings.sh --with-attributes     # + /v4/product/info/attributes
#   bin/capture-ozon-listings.sh --sku 3732855303      # только одна карточка, для разбора дефекта
#
# Ключи можно передать через окружение (OZON_CLIENT_ID / OZON_API_KEY) —
# тогда они не попадут в history шелла.

set -euo pipefail

BASE_URL="${OZON_BASE_URL:-https://api-seller.ozon.ru}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${SCRIPT_DIR}/../tests/Fixtures/Marketplace/Ozon/captured"
LIMIT=1000
MAX_PAGES=0          # 0 = выгрузить всё
INFO_CHUNK=1000      # лимит /v3/product/info/list
WITH_ATTRIBUTES=0
SINGLE_SKU=""

usage() {
    sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --limit)           LIMIT="$2"; shift 2 ;;
        --pages)           MAX_PAGES="$2"; shift 2 ;;
        --out)             OUT_DIR="$2"; shift 2 ;;
        --with-attributes) WITH_ATTRIBUTES=1; shift ;;
        --sku)             SINGLE_SKU="$2"; shift 2 ;;
        -h|--help)         usage 0 ;;
        *) echo "Неизвестный аргумент: $1" >&2; usage 1 ;;
    esac
done

command -v jq   >/dev/null || { echo "Нужен jq"   >&2; exit 1; }
command -v curl >/dev/null || { echo "Нужен curl" >&2; exit 1; }

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
# Падает с телом ответа, если Ozon вернул не 200 — молчаливый пустой файл хуже ошибки.
ozon_post() {
    local endpoint="$1" body="$2" out="$3" code
    code="$(curl -sS -o "$out" -w '%{http_code}' \
        -X POST "${BASE_URL}${endpoint}" \
        -H "Client-Id: ${OZON_CLIENT_ID}" \
        -H "Api-Key: ${OZON_API_KEY}" \
        -H 'Content-Type: application/json' \
        --max-time 120 \
        -d "$body")"

    if [[ "$code" != "200" ]]; then
        echo "  ✗ ${endpoint} → HTTP ${code}" >&2
        head -c 2000 "$out" >&2; echo >&2
        rm -f "$out"
        exit 1
    fi

    jq '.' "$out" > "${out}.tmp" && mv "${out}.tmp" "$out"
}

echo "→ Выгрузка в ${OUT_DIR}"
echo

# ── Режим одного SKU: проверить, что именно Ozon отдаёт по конкретному товару ──
if [[ -n "$SINGLE_SKU" ]]; then
    out="${OUT_DIR}/product-info-list.sku-${SINGLE_SKU}.json"
    body="$(jq -nc --argjson sku "$SINGLE_SKU" '{offer_id: [], product_id: [], sku: [$sku]}')"
    ozon_post /v3/product/info/list "$body" "$out"

    echo "Ответ по SKU ${SINGLE_SKU} → $(basename "$out")"
    echo
    echo "Ключевые поля:"
    jq '.items[] | {id, name, offer_id, sku, created_at,
                    barcodes, price, statuses,
                    sources: [.sources[]? | {sku, source, created_at}]}' "$out"
    exit 0
fi

# ── 1. /v3/product/list — идентификаторы всего каталога (product_id + offer_id) ──
echo "1. POST /v3/product/list"
last_id=""
page=0
: > "${OUT_DIR}/.product-ids"

while :; do
    page=$((page + 1))
    out="${OUT_DIR}/$(printf 'product-list.page-%02d.json' "$page")"

    body="$(jq -nc --arg last_id "$last_id" --argjson limit "$LIMIT" \
        '{filter: {visibility: "ALL"}, last_id: $last_id, limit: $limit}')"
    ozon_post /v3/product/list "$body" "$out"

    count="$(jq '.result.items | length' "$out")"
    total="$(jq '.result.total // 0' "$out")"
    last_id="$(jq -r '.result.last_id // ""' "$out")"
    jq -r '.result.items[].product_id' "$out" >> "${OUT_DIR}/.product-ids"

    echo "   страница ${page}: ${count} товаров (total=${total}) → $(basename "$out")"

    [[ "$count" -eq 0 || -z "$last_id" ]] && break
    [[ "$MAX_PAGES" -gt 0 && "$page" -ge "$MAX_PAGES" ]] && break
done

sort -u -o "${OUT_DIR}/.product-ids" "${OUT_DIR}/.product-ids"
product_count="$(wc -l < "${OUT_DIR}/.product-ids" | tr -d ' ')"
echo "   всего product_id: ${product_count}"
echo

# ── 2. /v3/product/info/list — карточки: name, offer_id, sku, barcodes, price ──
echo "2. POST /v3/product/info/list"
chunk=0
while read -r -a ids; do
    [[ ${#ids[@]} -eq 0 ]] && continue
    chunk=$((chunk + 1))
    out="${OUT_DIR}/$(printf 'product-info-list.chunk-%02d.json' "$chunk")"

    body="$(printf '%s\n' "${ids[@]}" | jq -sc '{offer_id: [], product_id: ., sku: []}')"
    ozon_post /v3/product/info/list "$body" "$out"

    echo "   чанк ${chunk}: запрошено ${#ids[@]}, получено $(jq '.items | length' "$out") → $(basename "$out")"
done < <(xargs -n "$INFO_CHUNK" < "${OUT_DIR}/.product-ids")
echo

# ── 3. /v4/product/info/attributes — бренд, категория, атрибуты (по флагу) ──
if [[ "$WITH_ATTRIBUTES" -eq 1 ]]; then
    echo "3. POST /v4/product/info/attributes"
    last_id=""
    page=0
    while :; do
        page=$((page + 1))
        out="${OUT_DIR}/$(printf 'product-info-attributes.page-%02d.json' "$page")"

        body="$(jq -nc --arg last_id "$last_id" --argjson limit "$LIMIT" \
            '{filter: {visibility: "ALL"}, last_id: $last_id, limit: $limit}')"
        ozon_post /v4/product/info/attributes "$body" "$out"

        count="$(jq '.result | length' "$out")"
        last_id="$(jq -r '.last_id // ""' "$out")"
        echo "   страница ${page}: ${count} карточек → $(basename "$out")"

        [[ "$count" -eq 0 || -z "$last_id" ]] && break
        [[ "$MAX_PAGES" -gt 0 && "$page" -ge "$MAX_PAGES" ]] && break
    done
    echo
fi

# ── Манифест: что именно и когда снято ──
jq -n \
    --arg captured_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --arg base_url "$BASE_URL" \
    --argjson product_count "$product_count" \
    --argjson limit "$LIMIT" \
    --argjson with_attributes "$WITH_ATTRIBUTES" \
    '{captured_at: $captured_at, base_url: $base_url, product_count: $product_count,
      page_limit: $limit, with_attributes: ($with_attributes == 1),
      endpoints: ["/v3/product/list", "/v3/product/info/list"]
                 + (if $with_attributes == 1 then ["/v4/product/info/attributes"] else [] end)}' \
    > "${OUT_DIR}/_meta.json"

rm -f "${OUT_DIR}/.product-ids"

echo "Готово. Файлы:"
ls -1sh "$OUT_DIR"
