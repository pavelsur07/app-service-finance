#!/usr/bin/env bash
#
# Внешнее ревью другим агентом — один раунд одной командой.
#
# Политика (когда обязательно, сколько раундов, что считается зелёным) —
# AGENTS.md §7.2. Механика и обработка сбоев — docs/workflow/external-review.md.
# Промпт живёт здесь, чтобы каждый раунд собирался одинаково, а не руками.
#
# Использование:
#   site/bin/external-review.sh <base_commit> [опции]
#
#   --reviewer codex|claude   кто ревьюит. По умолчанию: из Claude Code
#                             (CLAUDECODE=1) — codex, иначе — claude
#   --effort high|medium      усилие ревьюера; medium для Small-задач
#   --context <file>          дописать в промпт: уже исправленные находки
#                             внутреннего review, факты о схеме и объёмах,
#                             которые ревьюер без шелла не добудет сам
#   --out <dir>               куда писать prompt.txt, diff.patch, review.txt
#                             (по умолчанию var/external-review/<base8>)
#   --force                   запустить, даже если этот дифф уже зелёный
#
# Коды возврата:
#   0 — REVIEW_GREEN (или дифф не изменился с последнего зелёного раунда)
#   1 — есть находки, читать review.txt
#   2 — ошибка аргументов или окружения
#   3 — ревьюер не завершился: таймаут, сбой команды, нет маркера при пустом
#       выводе; повторить один раз после исправления, дальше — STOP
#
# В дифф попадают только site/src, site/tests, site/config, site/migrations,
# site/templates, site/assets — то, что меняет поведение. Документация,
# lock-файлы, фикстуры и снапшоты ревьюеру не показываются. Сравнение идёт с
# рабочим деревом, поэтому незакоммиченные и новые файлы тоже входят.

set -euo pipefail

base=${1:-}
if [ -z "$base" ]; then
    echo "первым аргументом — базовый коммит (stage_base_commit или база задачи)" >&2
    exit 2
fi
shift

reviewer=""
effort="high"
context_file=""
out_dir=""
force=0

while [ $# -gt 0 ]; do
    case "$1" in
        --reviewer) reviewer=${2:?}; shift 2 ;;
        --effort)   effort=${2:?}; shift 2 ;;
        --context)  context_file=${2:?}; shift 2 ;;
        --out)      out_dir=${2:?}; shift 2 ;;
        --force)    force=1; shift ;;
        *) echo "неизвестная опция: $1" >&2; exit 2 ;;
    esac
done

repo_root=$(git rev-parse --show-toplevel)
cd "$repo_root"

if ! git rev-parse --verify --quiet "${base}^{commit}" >/dev/null; then
    echo "базовый коммит не найден: $base" >&2
    exit 2
fi

if [ -z "$reviewer" ]; then
    if [ "${CLAUDECODE:-}" = "1" ]; then reviewer="codex"; else reviewer="claude"; fi
fi
case "$reviewer" in codex|claude) ;; *) echo "--reviewer: codex или claude" >&2; exit 2 ;; esac
case "$effort" in high|medium) ;; *) echo "--effort: high или medium" >&2; exit 2 ;; esac
if ! command -v "$reviewer" >/dev/null; then
    echo "ревьюер '$reviewer' не установлен" >&2
    exit 2
fi
if [ -n "$context_file" ] && [ ! -r "$context_file" ]; then
    echo "файл контекста не читается: $context_file" >&2
    exit 2
fi

base_full=$(git rev-parse "$base")
[ -n "$out_dir" ] || out_dir="site/var/external-review/${base_full:0:8}"
mkdir -p "$out_dir"

paths=(site/src site/tests site/config site/migrations site/templates site/assets)
excludes=(':(exclude)**/*.lock' ':(exclude)**/__snapshots__/**' ':(exclude)**/Fixtures/**' ':(exclude)**/fixtures/**')

diff_file="$out_dir/diff.patch"
{
    git diff "$base_full" -- "${paths[@]}" "${excludes[@]}"
    git ls-files --others --exclude-standard -- "${paths[@]}" "${excludes[@]}" | while IFS= read -r f; do
        git diff --no-index -- /dev/null "$f" || true
    done
} > "$diff_file"

if [ ! -s "$diff_file" ]; then
    echo "дифф пуст: в отслеживаемых путях нет изменений относительно $base_full" >&2
    echo "REVIEW_GREEN" > "$out_dir/review.txt"
    exit 0
fi

diff_hash=$(sha256sum "$diff_file" | cut -d' ' -f1)
green_marker="$out_dir/green.sha256"
if [ "$force" -eq 0 ] && [ -f "$green_marker" ] && [ "$(cat "$green_marker")" = "$diff_hash" ]; then
    echo "дифф не изменился с последнего зелёного раунда ($out_dir/review.txt) — повтор не нужен"
    exit 0
fi

prompt_file="$out_dir/prompt.txt"
cat > "$prompt_file" <<EOF
You are an independent senior reviewer invoked by another agent. Read-only:
do not edit files, do not change Git state, do not call external services,
do not start another reviewer. Do not read .env files, credentials, keys or
production dumps.

Review the diff ${base_full}..working tree of the repository
app-service-finance (Symfony 7.4, PHP in site/). Project rules: AGENTS.md and
CLAUDE.md; code patterns in PATTERNS.md; contracts in ARCHITECTURE.md —
consult only the sections the diff touches.

Skip everything the machine gates already enforce: code style and formatting,
declare(strict_types=1), module boundaries, missing companyId parameters in
Repository signatures, debug calls. Those are caught by PHP CS Fixer, PHPStan
and the architecture tests; reporting them wastes the round.

Check, only where relevant to this diff:
- scope compliance against the task;
- correctness and edge cases;
- company isolation and IDOR at the call sites: a Repository method may take
  companyId and still not use it; inherited find()/findBy()/findAll() calls;
- authorization; financial calculations, Money, signs, periods, mappings;
- transactions, idempotency, concurrency, Messenger retries and log levels;
- migrations: indexes on new foreign keys, nullable/default, locks, data safety;
- N+1 and unbounded lists;
- test quality: does the test assert behavior, would it fail on the old code;
- secrets, PII, unnecessary complexity.

Report only BLOCKER and IMPORTANT findings, plus MINOR findings that are
trivially fixable (at most five). For each: severity, file:line, evidence,
impact, concrete fix. No general advice, no restating the diff, no requests
for a re-review, no summary of what the change does.

If there is no BLOCKER and no IMPORTANT finding, end the response with the
exact standalone line:
REVIEW_GREEN
EOF

if [ -n "$context_file" ]; then
    {
        echo
        echo "--- CONTEXT FROM THE IMPLEMENTING AGENT ---"
        cat "$context_file"
    } >> "$prompt_file"
fi

review_file="$out_dir/review.txt"
: > "$review_file"
rc=0

case "$reviewer" in
    codex)
        { cat "$prompt_file"; echo; echo '--- DIFF ---'; cat "$diff_file"; } \
            | timeout 900 codex exec -s read-only --ephemeral \
                -c "model_reasoning_effort=\"$effort\"" \
                -o "$review_file" - >/dev/null 2>"$out_dir/stderr.log" || rc=$?
        ;;
    claude)
        { cat "$prompt_file"; echo; echo '--- DIFF ---'; cat "$diff_file"; } \
            | timeout 900 claude -p \
                --safe-mode \
                --permission-mode dontAsk \
                --effort "$effort" \
                --tools "Read,Glob,Grep,Bash" \
                --allowedTools "Read" "Glob" "Grep" \
                    "Bash(git status)" "Bash(git status *)" "Bash(git diff)" "Bash(git diff *)" \
                    "Bash(git log)" "Bash(git log *)" "Bash(git show)" "Bash(git show *)" \
                    "Bash(git rev-parse *)" "Bash(git merge-base *)" \
                --disallowedTools "Edit" "Write" "NotebookEdit" "WebFetch" "WebSearch" "mcp__*" \
                --strict-mcp-config \
                --no-session-persistence \
                --max-turns 40 \
                --output-format text \
                "Review the diff and rules given on stdin. Use the read-only tools only to check surrounding code." \
                > "$review_file" 2>"$out_dir/stderr.log" || rc=$?
        ;;
esac

if [ "$rc" -eq 124 ]; then
    echo "ревьюер не уложился в 900 с — не зелёный раунд; см. $out_dir/stderr.log" >&2
    exit 3
fi
if [ "$rc" -ne 0 ] || [ ! -s "$review_file" ]; then
    echo "ревьюер завершился с кодом $rc или пустым выводом — не зелёный раунд; см. $out_dir/stderr.log" >&2
    exit 3
fi

last_line=$(grep -v '^[[:space:]]*$' "$review_file" | tail -n 1 | tr -d '[:space:]')
if [ "$last_line" = "REVIEW_GREEN" ]; then
    echo "$diff_hash" > "$green_marker"
    echo "REVIEW_GREEN — $reviewer, effort=$effort, дифф $(wc -l < "$diff_file") строк, отчёт $review_file"
    exit 0
fi

rm -f "$green_marker"
echo "находки ревьюера — $review_file"
exit 1
