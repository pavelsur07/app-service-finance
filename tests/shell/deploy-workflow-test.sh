#!/usr/bin/env bash

# Инварианты скрипта деплоя в .github/workflows/deploy.yml; production не вызывается.
#
# 1. Внутри `ssh … << 'EOF'` удалённый bash читает команды из stdin. Любой
#    `docker compose run|exec` без своего перенаправления stdin выедает остаток
#    скрипта: с 17.10.2026 `run -T ozon-fetcher -migrate` обрывал деплой перед [5/5].
# 2. Каждый воркер Messenger обновляется явным шагом [4/5], а не только
#    «добором» [5/5]: так wb-finance жил на старом образе.

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
workflow=${DEPLOY_WORKFLOW:-$repo_root/.github/workflows/deploy.yml}
compose=${DEPLOY_COMPOSE:-$repo_root/docker-compose.prod.yml}
failures=0

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    failures=$((failures + 1))
}

# Логические команды внутри ssh-heredoc: продолжения строк через «\» склеены.
heredoc_commands() {
    awk '
        /ssh .*<< *'\''EOF'\''/ { inside = 1; next }
        inside && /^[[:space:]]*EOF[[:space:]]*$/ { inside = 0; next }
        inside {
            line = $0
            sub(/^[[:space:]]+/, "", line)
            if (buffer != "") { line = buffer " " line }
            if (line ~ /\\$/) { sub(/\\$/, "", line); buffer = line; next }
            buffer = ""
            printf "%d\t%s\n", NR, line
        }
    ' "$workflow"
}

# Своё перенаправление stdin: «< /dev/null» или «< файл», но не heredoc «<<».
stdin_redirect='(^|[^<])<[[:space:]]*[^<[:space:]]'
checked=0
while IFS=$'\t' read -r line_no command; do
    [[ $command =~ ^# ]] && continue
    [[ $command =~ docker\ compose.*[[:space:]](run|exec)[[:space:]] ]] || continue
    checked=$((checked + 1))
    if ! [[ $command =~ $stdin_redirect ]]; then
        fail "deploy.yml:$line_no: docker compose run/exec в ssh-heredoc без перенаправления stdin: $command"
    fi
done < <(heredoc_commands)

if [[ $checked -eq 0 ]]; then
    fail "не найдено ни одного docker compose run/exec в ssh-heredoc — разбор deploy.yml сломан"
fi

step4_up=$(awk '/\[4\/5\]/ { found = 1; next } found && /docker compose .* up / { print; exit }' "$workflow")
if [[ -z $step4_up ]]; then
    fail "не найдена команда up шага [4/5]"
fi

workers=$(grep -oE '^    site-messenger-worker-[a-z0-9-]+:' "$compose" | tr -d ' :' || true)
if [[ -z $workers ]]; then
    fail "в $compose не найдено ни одного site-messenger-worker-*"
fi
for worker in $workers; do
    if ! [[ " $step4_up " =~ [[:space:]]$worker[[:space:]] ]]; then
        fail "воркер $worker не обновляется шагом [4/5]: $step4_up"
    fi
done

if [[ $failures -gt 0 ]]; then
    printf '%d failure(s)\n' "$failures" >&2
    exit 1
fi

printf 'OK: %d команд run/exec в ssh-heredoc, воркеры в [4/5]: %s\n' "$checked" "$(echo $workers)"
