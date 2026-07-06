#!/usr/bin/env bash
# triage.sh <issue_id> — diagnose a GlitchTip incident with a sandboxed headless Claude.
# Read-only: the agent may only read code and call ./gt.sh / ./verify.sh. It proposes
# fixes, never applies them. No --dangerously-skip-permissions.
set -euo pipefail
cd "$(dirname "$0")"

ISSUE="${1:-}"
[ -n "$ISSUE" ] || { echo "usage: triage.sh <issue_id>" >&2; exit 1; }

BRIEF=$(./gt.sh brief "$ISSUE")

mkdir -p reports
OUT="reports/triage-${ISSUE}-$(date +%F).md"

PROMPT="Ты триажишь инцидент GlitchTip #$ISSUE в PHP/Symfony репозитории (исходники под site/src).
Это ФАЗА ДИАГНОСТИКИ: НЕ меняй прод, НЕ пиши и НЕ применяй код — только предлагай.

Бриф инцидента (из ./gt.sh brief $ISSUE):
$BRIEF

Сделай:
1. Классифицируй тип: cron-контекст (без стектрейса) ИЛИ exception (со стектрейсом).
2. Найди причину в репозитории, ссылайся на файл:строку. Используй Read/Grep/Glob по site/src,
   и ./gt.sh show $ISSUE если нужно полное событие.
3. Если поможет read-only проверка на проде — вызови ./verify.sh (ТОЛЬКО его whitelisted команды).
   Никогда не вызывай codex-console напрямую.
4. Верни markdown-отчёт РОВНО с этими секциями:
   ## Диагноз (со ссылками файл:строка)
   ## Что проверено на проде (команды через ./verify.sh + результат, или «ничего»)
   ## Варианты фикса (2–3, с трейд-оффами; НЕ применять)
Ограничение: только диагностика, менять прод и код запрещено."

claude -p "$PROMPT" \
  --allowedTools "Read" "Grep" "Glob" "Bash(./gt.sh:*)" "Bash(./verify.sh:*)" \
  --output-format text | tee "$OUT"

echo
echo "Отчёт сохранён: $OUT"
