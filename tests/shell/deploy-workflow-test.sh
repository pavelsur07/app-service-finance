#!/usr/bin/env bash

# Инварианты скрипта деплоя в .github/workflows/deploy.yml; production не вызывается.
#
# 1. Внутри `ssh … << 'EOF'` удалённый bash читает команды из stdin. Любой
#    `docker compose run|exec` без своего перенаправления stdin выедает остаток
#    скрипта: с 17.10.2026 `run -T ozon-fetcher -migrate` обрывал деплой перед [5/5].
# 2. async_sync обновляется до PHP-FPM, остальные Messenger workers —
#    явным шагом [4/5], до общего «добора» [5/5].

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

# Consumer-first is intentional: file import producers must not outrun handlers.
sync_line=$(heredoc_commands | awk -F '\t' '$2 ~ /docker compose .* up .*--wait site-messenger-worker-sync$/  && !found { print $1; found = 1 }')
fpm_line=$(heredoc_commands | awk -F '\t' '$2 ~ /docker compose .* up .*--wait site-php-fpm$/  && !found { print $1; found = 1 }')
if [[ -z $sync_line || -z $fpm_line || $sync_line -ge $fpm_line ]]; then
    fail "async_sync должен явно обновляться с --wait до PHP-FPM"
fi

workers=$(grep -oE '^    site-messenger-worker-[a-z0-9-]+:' "$compose" | tr -d ' :' || true)
if [[ -z $workers ]]; then
    fail "в $compose не найдено ни одного site-messenger-worker-*"
fi
for worker in $workers; do
    [[ $worker == site-messenger-worker-sync ]] && continue
    if [[ $worker == site-messenger-worker-payment-intake ]]; then
        # Только этот worker условный. Поведение веток проверяет stdlib runtime unittest
        # (make test-deploy-workflow), остальные workers остаются обязательными в [4/5].
        intake_guard=$(heredoc_commands | awk -F '\t' '
            $2 == "if [ \"$TELEGRAM_PAYMENT_INTAKE_ENABLED\" = \"1\" ] && [ -n \"$TELEGRAM_PAYMENT_INTAKE_COMPANIES\" ]; then" { inside = 1 }
            inside { print $2 }
            inside && $2 == "fi" { inside = 0 }
        ')
        intake_up='docker compose -f docker-compose.prod.yml --profile payment-intake up -d --no-deps site-messenger-worker-payment-intake'
        intake_stop='docker compose -f docker-compose.prod.yml --profile payment-intake stop site-messenger-worker-payment-intake'
        if [[ $intake_guard != *"$intake_up"* || $intake_guard != *"$intake_stop"* ]]; then
            fail "payment-intake worker требует guard flag=1 + непустой scope, explicit profile up/stop без --wait"
        fi
        if [[ " $step4_up " == *" $worker "* ]]; then
            fail "payment-intake worker не должен стартовать безусловно в [4/5]"
        fi
        continue
    fi
    if ! [[ " $step4_up " =~ [[:space:]]$worker[[:space:]] ]]; then
        fail "воркер $worker не обновляется шагом [4/5]: $step4_up"
    fi
done

if [[ $failures -gt 0 ]]; then
    printf '%d failure(s)\n' "$failures" >&2
    exit 1
fi

printf 'OK: %d команд run/exec в ssh-heredoc, async_sync до FPM; обычные воркеры в [4/5], payment-intake под guard: %s\n' "$checked" "$(echo $workers)"
