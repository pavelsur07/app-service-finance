#!/usr/bin/env bash
# verify.sh — narrow READ-ONLY prod allowlist for the autonomous triage loop.
#
# The codex-console wrapper (sudo /usr/local/bin/codex-console) ALSO permits mutating
# commands (messenger:consume, rolling-refresh --execute, prune, daily-maintenance).
# The triage loop runs WITHOUT human approval, so it must never get the full wrapper.
# This script is its own boundary: only the read-only commands below are ever forwarded,
# every caller argument is validated, and memory-heavy scans are hard-capped.
set -euo pipefail

SSH_ALIAS="vf-prod-codex"
WRAPPER="sudo /usr/local/bin/codex-console"

deny() { echo "verify.sh: DENIED — $*" >&2; exit 2; }

CMD="${1:-}"
[ -n "$CMD" ] || deny "no command given"
shift || true
EXTRA=("$@")

# Trust boundary: each caller arg must be a plain --flag or --flag=value.
# Rejects shell metacharacters, spaces, quoting — nothing dangerous reaches ssh.
for a in "${EXTRA[@]}"; do
    [[ "$a" =~ ^--[a-z][a-z-]*(=[A-Za-z0-9:._-]+)?$ ]] || deny "unsafe argument: $a"
done

flag_name() { local f="${1#--}"; echo "${f%%=*}"; }

# Every EXTRA flag must be in the allowed narrowing set for this command.
allow_only() {
    local allowed=" $* "
    for a in "${EXTRA[@]}"; do
        [[ "$allowed" == *" $(flag_name "$a") "* ]] || deny "flag not allowed for $CMD: $a"
    done
}

run() { exec ssh "$SSH_ALIAS" "$WRAPPER $*"; }

case "$CMD" in
    messenger:stats|app:ingestion:marketplace-categories:status)
        [ ${#EXTRA[@]} -eq 0 ] || deny "$CMD takes no arguments"
        run "$CMD"
        ;;
    app:ingestion:ozon-accrual:verify-rolling-refresh)
        # limit / raw-limit / raw-row-limit are FIXED here and cannot be raised by the
        # caller — guarantees the autonomous run stays under the 512M wrapper cap.
        # Caller may only narrow scope: --company-id / --shop-ref / --days-back.
        allow_only company-id shop-ref days-back
        run "$CMD --limit=1 --raw-limit=8 --raw-row-limit=8000 --no-interaction ${EXTRA[*]}"
        ;;
    app:ingestion:ozon-accrual:preview-normalization)
        # READ-ONLY только пока recordUnknown=false. В коде есть persist/markSeen в
        # OzonAccrualCategoryTaxonomyResolver (recordUnknown()), закрытый дефолтом false.
        # НЕ добавлять в allow_only флаги вроде --record-unknown — это включит запись категорий на прод.
        # Verified read-only: only SELECT queries + raw-storage reads + stdout,
        # no persist/flush/dispatch/DDL. Scan limits capped for the same OOM reason.
        # Command requires --company-id/--from/--to (it errors itself if missing).
        allow_only company-id from to
        run "$CMD --raw-limit=4 --raw-row-limit=8000 --sample-limit=50 ${EXTRA[*]}"
        ;;
    *)
        deny "command not in read-only whitelist: $CMD"
        ;;
esac
