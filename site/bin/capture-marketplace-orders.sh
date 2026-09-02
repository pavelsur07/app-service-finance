#!/usr/bin/env bash
#
# Снимок заказов Ozon и Wildberries в JSON-фикстуры.
#
# Назначение — получить реальные ответы обоих маркетплейсов (реальные поля,
# реальные наборы статусов), чтобы проектировать хранение по факту, а не по
# документации.
#
# Ничего не пишет в БД и не трогает приложение: только curl + файлы.
#
# Использование:
#   bin/capture-marketplace-orders.sh                 # спросит ключи обоих
#   bin/capture-marketplace-orders.sh --ozon          # только Ozon
#   bin/capture-marketplace-orders.sh --wb --days 7   # только WB, окно 7 дней
#
# Ключи можно передать окружением, тогда они не попадут в history:
#   OZON_CLIENT_ID, OZON_API_KEY, WB_API_KEY

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${SCRIPT_DIR}/../tests/Fixtures/Marketplace/Orders/captured"
DAYS=2
LIMIT=100
DO_OZON=0
DO_WB=0

usage() { sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --ozon)  DO_OZON=1; shift ;;
        --wb)    DO_WB=1; shift ;;
        --days)  DAYS="$2"; shift 2 ;;
        --limit) LIMIT="$2"; shift 2 ;;
        --out)   OUT_DIR="$2"; shift 2 ;;
        -h|--help) usage 0 ;;
        *) echo "Неизвестный аргумент: $1" >&2; usage 1 ;;
    esac
done

# Без явных флагов снимаем оба маркетплейса.
if [[ "$DO_OZON" -eq 0 && "$DO_WB" -eq 0 ]]; then DO_OZON=1; DO_WB=1; fi

command -v jq   >/dev/null || { echo "Нужен jq"   >&2; exit 1; }
command -v curl >/dev/null || { echo "Нужен curl" >&2; exit 1; }

mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"

SINCE_ISO="$(date -u -d "-${DAYS} days" +%Y-%m-%dT%H:%M:%SZ)"
TO_ISO="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

echo "→ Окно: ${SINCE_ISO} .. ${TO_ISO}"
echo "→ Выгрузка в ${OUT_DIR}"
echo

# save <файл> <http-код> — красиво раскладывает ответ и не глотает ошибку
save() {
    local out="$1" code="$2" label="$3"
    if [[ "$code" != "200" ]]; then
        echo "   ✗ ${label} → HTTP ${code}"
        head -c 400 "$out" | sed 's/^/     /'
        echo
        mv "$out" "${out%.json}.error-${code}.json" 2>/dev/null || true
        return 1
    fi
    jq '.' "$out" > "${out}.tmp" && mv "${out}.tmp" "$out"
    echo "   ✓ ${label} → $(basename "$out")"
}

# ─────────────────────────── OZON ───────────────────────────
if [[ "$DO_OZON" -eq 1 ]]; then
    [[ -n "${OZON_CLIENT_ID:-}" ]] || read -rp "Ozon Client-Id: " OZON_CLIENT_ID
    [[ -n "${OZON_API_KEY:-}"   ]] || { read -rsp "Ozon Api-Key: " OZON_API_KEY; echo; }

    ozon_post() {
        local endpoint="$1" body="$2" out="$3"
        local code
        code="$(curl -sS -o "$out" -w '%{http_code}' -X POST "https://api-seller.ozon.ru${endpoint}" \
            -H "Client-Id: ${OZON_CLIENT_ID}" -H "Api-Key: ${OZON_API_KEY}" \
            -H 'Content-Type: application/json' --max-time 120 -d "$body")"
        save "$out" "$code" "$endpoint"
    }

    echo "OZON"
    # FBS-отправления: схема, где продавец сам собирает и отгружает.
    ozon_post /v3/posting/fbs/list "$(jq -nc \
        --arg since "$SINCE_ISO" --arg to "$TO_ISO" --argjson limit "$LIMIT" \
        '{dir:"ASC", filter:{since:$since, to:$to}, limit:$limit, offset:0,
          with:{analytics_data:true, financial_data:true}}')" \
        "$OUT_DIR/ozon-posting-fbs-list.json"

    # FBO-отправления: со склада Ozon. Набор статусов у схем разный.
    ozon_post /v2/posting/fbo/list "$(jq -nc \
        --arg since "$SINCE_ISO" --arg to "$TO_ISO" --argjson limit "$LIMIT" \
        '{dir:"ASC", filter:{since:$since, to:$to}, limit:$limit, offset:0, translit:true,
          with:{analytics_data:true, financial_data:true}}')" \
        "$OUT_DIR/ozon-posting-fbo-list.json"

    # Перечитывание ОДНОГО отправления по номеру — механика часового перепроса
    # статусов. Список фильтруется по времени СОЗДАНИЯ и заказ отдаёт один раз,
    # поэтому дальнейшие смены статуса видны только через эти эндпоинты.
    #
    # Номер берётся из уже снятого списка: отдельного ввода не требуется, а на
    # пустом окне шаг просто пропускается.
    for scheme in fbs fbo; do
        list_file="$OUT_DIR/ozon-posting-${scheme}-list.json"
        [[ -s "$list_file" ]] || continue

        if [[ "$scheme" == "fbs" ]]; then
            posting="$(jq -r '.result.postings[0].posting_number // empty' "$list_file" 2>/dev/null || true)"
            get_endpoint=/v3/posting/fbs/get
        else
            posting="$(jq -r '.result[0].posting_number // empty' "$list_file" 2>/dev/null || true)"
            get_endpoint=/v2/posting/fbo/get
        fi

        if [[ -z "$posting" ]]; then
            echo "   — ${get_endpoint} пропущен: в списке ${scheme} нет отправлений"
            continue
        fi

        # Тело ровно такое же, как у OzonOrdersClient::fetchPosting(): образец
        # должен показывать ответ на РЕАЛЬНЫЙ запрос. Дополнительные блоки
        # (`analytics_data`, `financial_data`) production-клиент не просит, и
        # снимать их значило бы класть в выгрузку лишние персональные данные.
        ozon_post "$get_endpoint" "$(jq -nc --arg pn "$posting" '{posting_number:$pn}')" \
            "$OUT_DIR/ozon-posting-${scheme}-get.json"
    done
    echo
fi

# ──────────────────────────── WB ────────────────────────────
if [[ "$DO_WB" -eq 1 ]]; then
    [[ -n "${WB_API_KEY:-}" ]] || { read -rsp "WB Api-Key: " WB_API_KEY; echo; }

    wb_get() {
        local url="$1" out="$2" label="$3"
        local code
        code="$(curl -sS -o "$out" -w '%{http_code}' "$url" \
            -H "Authorization: ${WB_API_KEY}" --max-time 120)"
        save "$out" "$code" "$label"
    }

    echo "WILDBERRIES"
    # Статистика: исторический поток заказов. Жёсткий лимит — 1 запрос в минуту.
    wb_get "https://statistics-api.wildberries.ru/api/v1/supplier/orders?dateFrom=${SINCE_ISO%Z}&flag=0" \
        "$OUT_DIR/wb-statistics-orders.json" "statistics /api/v1/supplier/orders"

    # Marketplace FBS: оперативный список сборочных заданий.
    SINCE_UNIX="$(date -u -d "-${DAYS} days" +%s)"
    wb_get "https://marketplace-api.wildberries.ru/api/v3/orders?limit=${LIMIT}&next=0&dateFrom=${SINCE_UNIX}" \
        "$OUT_DIR/wb-marketplace-orders.json" "marketplace /api/v3/orders"

    # Статусы по id из предыдущего ответа — именно они нужны для отслеживания.
    if [[ -f "$OUT_DIR/wb-marketplace-orders.json" ]]; then
        IDS="$(jq -c '[.orders[]?.id] | .[0:100]' "$OUT_DIR/wb-marketplace-orders.json" 2>/dev/null || echo '[]')"
        if [[ "$IDS" != "[]" && -n "$IDS" ]]; then
            out="$OUT_DIR/wb-marketplace-orders-status.json"
            code="$(curl -sS -o "$out" -w '%{http_code}' -X POST \
                "https://marketplace-api.wildberries.ru/api/v3/orders/status" \
                -H "Authorization: ${WB_API_KEY}" -H 'Content-Type: application/json' \
                --max-time 120 -d "$(jq -nc --argjson o "$IDS" '{orders:$o}')")"
            save "$out" "$code" "marketplace /api/v3/orders/status"
        else
            echo "   — статусы пропущены: в /api/v3/orders нет id (пустое окно?)"
        fi
    fi
    echo
fi

jq -n --arg captured_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
      --arg since "$SINCE_ISO" --arg to "$TO_ISO" --argjson days "$DAYS" \
      '{captured_at:$captured_at, window:{since:$since, to:$to, days:$days}}' \
      > "$OUT_DIR/_meta.json"

echo "Готово. Файлы:"
ls -1sh "$OUT_DIR"
