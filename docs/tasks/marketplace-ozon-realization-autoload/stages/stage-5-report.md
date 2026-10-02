# Stage 5 Report: cron, гейт конца окна, документация

Сделано: гейт `app:marketplace:ozon-realization-check` (`--company-id`, `--report-only`; активен с 9-го по 16-е число; красное — нет отчёта, `auth_failed`, сбой; `conflict` — warning; применённой считается пара в `success` или любые строки `marketplace_ozon_realizations` за месяц),
`OzonRealizationAppliedQuery`; cron: `17 * * * *` опрос и `20 7 * * *` гейт вместо `0 6 5 * *`; `ARCHITECTURE.md`, `health-gates.md`, `prod-access.md`.
Проверки: integration гейта 5 OK (вне периода молчит, красный с одним агрегированным error, зелёный при `success`/ручном применении, `conflict` = warning, `--report-only`, `--company-id`); PHPStan `src/Marketplace/Ozon` — 0; cs — 0.
Internal review: BLOCKER/IMPORTANT нет.
