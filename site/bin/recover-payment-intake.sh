#!/usr/bin/env bash

# Company-scoped recovery only; financial confirmation remains a user action.
set -euo pipefail

enabled=${TELEGRAM_PAYMENT_INTAKE_ENABLED:-0}
companies=${TELEGRAM_PAYMENT_INTAKE_COMPANIES:-}
case "$enabled" in
    0|1) ;;
    *) echo 'TELEGRAM_PAYMENT_INTAKE_ENABLED must be empty, 0 or 1' >&2; exit 1 ;;
esac

uuid='[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}'
csv="^${uuid}(,${uuid})*$"
# Validate the entire list before any command, including its first valid member.
if [[ -n "$companies" && ! "$companies" =~ $csv ]]; then
    echo 'TELEGRAM_PAYMENT_INTAKE_COMPANIES must be canonical UUID CSV' >&2
    exit 1
fi
[[ "$enabled" == 1 && -n "$companies" ]] || exit 0

IFS=',' read -r -a company_ids <<< "$companies"
declare -A seen=()
status=0
for company in "${company_ids[@]}"; do
    company=${company,,}
    [[ -z "${seen[$company]:-}" ]] || continue
    seen[$company]=1
    if ! php bin/console app:cash:recover-payment-intake "$company" --no-interaction --quiet; then
        echo 'Cash payment-intake recovery failed; continuing delivery and remaining companies' >&2
        status=1
    fi
    if ! php bin/console app:telegram:recover-payment-intake "$company" --skip-cash-recovery --no-interaction --quiet; then
        echo 'Telegram payment-intake recovery failed; continuing remaining companies' >&2
        status=1
    fi
done
exit "$status"
