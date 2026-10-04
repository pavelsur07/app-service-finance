# План (Large: cron, массовая запись в учёт через существующий обработчик)

## Stage 1: запрос и догоняющая команда
Risk: MEDIUM. DoD: `OzonUnappliedRealizationQuery` (границы месяцев, критерии «не применён», активное подключение, `companyId` в данных), команда `catchup` (`--months-back`, `--limit`, `--dry-run`, пачка, порядок, логи), тесты запроса и команды.
## Stage 2: гейт, cron, документация
Risk: MEDIUM (cron). DoD: команда `unapplied-check`, cron 05:30 и 07:25, `ARCHITECTURE.md`, `health-gates.md`, `prod-access.md`, тесты гейта.
## Handoff
Полные гейты, ревью в свежей сессии, внешнее ревью (Codex), `handoff.md`, Ready.
