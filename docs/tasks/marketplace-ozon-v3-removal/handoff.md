# marketplace-ozon-v3-removal — handoff

**Branch:** `refactor/marketplace-ozon-v3-removal` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2505 · **CI:** см. PR

## Summary of stages
- Stage 1 — `OzonAccrualSyncPlanner`; ручной и первичный синк Ozon и cron — через него; «Проверить» без реестра адаптеров.
- Stage 2 — удалены v3-цепочка, InitialSync-цепочка, `OzonAdapter`, реестр адаптеров, 2 маршрута Messenger; документация.

## Migrations
- none

## Public API / contract changes
- удалены CLI-команды `app:marketplace:ozon-daily-sync`, `app:marketplace:ozon-month-raw-refresh`; сообщения `SyncOzonReportMessage`, `InitialSyncMessage`
- `SyncConnectionAction` возвращает `SyncConnectionResult` вместо `int`; HTTP-маршруты не менялись

## Checks
- `make site-stan` — OK, baseline 2454 → 2410
- `make site-cs-check`, `make site-cs-strict-types` — OK
- `make site-test-unit` — OK 2978; `make site-test` — OK 5189
- `lint:container` — OK; `debug:messenger`/`list` — исчезло только удаляемое

## Reviews
- internal: Stage 1 — 1 итерация, Stage 2 — 1 итерация; BLOCKER/IMPORTANT нет
- external: rounds 1; IMPORTANT (пустое окно сдвигало lastSyncAt) — fixed without re-run, регрессионный тест; MINOR (компания в первичном синке) — fixed

## Production actions
1. **До мержа** (отдельное согласие, AGENTS.md §3.3): удалить из failed 56 сообщений `SyncOzonReportMessage`, id 1997–2052 (все от 09.09.2026 13:34, повторять бессмысленно — v3 снят). Проверено 23.09: ровно 56 id подряд, только этот класс; живые очереди пусты. Способ: `codex-console messenger:failed:remove <id> --force` × 56, затем проверка — в failed 5.
2. Merge → автодеплой → приёмка: воркеры healthy, failed = 5, GlitchTip без новых ошибок, 24.09 04:00 by-day-документы Ozon пишутся.

## Risks, limitations, follow-ups
- Первичный синк нового Ozon-кабинета не берёт дни до 08.09.2026 (порог общий; для новой компании его можно считать по её документам) — отдельная задача.
- Второй рубеж порога в `SyncOzonAccrualByDayHandler` — FOLLOW-UP.
- `docs/maintenance/codex-console.sh` и боевой wrapper всё ещё разрешают `ozon-daily-sync` — вызов вернёт «command not defined»; убрать при следующей правке wrapper'а (Владелец).
- Мёртвые `fetchSales/fetchCosts/fetchReturns` в `WildberriesAdapter` — этап 5.

## Owner decision
Ready: PR #2505 "fix(marketplace): синк Ozon через by-day, удалить легаси v3" — удалить 56 failed-сообщений SyncOzonReportMessage (id 1997–2052) до мержа, затем merge into master with automatic production deploy?
Reply: "remove failed 1997-2052, merge and deploy #2505"
