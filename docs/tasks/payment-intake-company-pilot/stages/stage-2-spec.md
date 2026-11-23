# Stage2 ТЗ и чек-лист
Risk HIGH-LOCAL. stage_base_commit:12eca1d079e9c4b6283b92935484af24e28fc923, recorded before runtime code.
Requirements TASK.md and plan Task2. Consumes Task1 env and --skip-cash-recovery.

- [x] Existing flag TELEGRAM_PAYMENT_INTAKE_ENABLED: default0, deployment accepts only empty/0/1. New TELEGRAM_PAYMENT_INTAKE_COMPANIES: canonical UUIDs separated by commas, no wildcard, no trailing/empty member, no newline/quotes; empty meansoff. UUID values from runtime variable only. Entire string validated before SSH interpolation.
- [x] Both x-php-env and scheduler receive both values; no shared AI grants change.
- [x] site-messenger-worker-payment-intake uses same PHP CLI immutable image, only async_payment_intake, resources/logs/network/mount patterns reused, profiles [payment-intake], healthcheck disabled.
- [x] Deploy start only flag1+valid nonempty scope, explicit profile up --no-deps without --wait. Else explicit profile stop. Profile not added to general COMPOSE_PROFILES; final general up cannot start disabled worker.
- [x] Cron every5min calls bash bin/recover-payment-intake.sh. Script starts no command off/empty; malformed entire scope nonzero with no calls. For each normalized unique company run Cash and Telegram --skip-cash-recovery independently, continue after failures, aggregate nonzero exit. Never calls Confirm or invokes AI model itself.
- [x] Existing deploy-workflow worker scanner distinguishes guarded worker and verifies guard via behavior tests, does not weaken existing unconditional workers.
- [x] Only parse_payment_message route changes: mode single, models [deepseek/deepseek-flash]. Categorize/source-type remain existing fallback. Other model thresholds/gold/prompts stay unchanged. New nightly fingerprint, no replay/current-day reset.
- [x] Tests-first RED→GREEN for runtime behavior and route invariants; fake PHP/docker no prod effects. Shell/compose, targeted pytest, lint/types.
- [x] Stage complete diff internal review, report, commit/push/PR; external if >500lines. Handoff formal external includes workflow/compose/cron context absent collector.
