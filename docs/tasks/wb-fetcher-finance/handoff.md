# wb-fetcher-finance — handoff

## Чек-лист

- [x] этапы 0–5 закрыты по своим чек-листам (этап 5 — кроме проверок после деплоя)
- [x] полные гейты site: `site-stan` (изменённые файлы 0; полный — см. «Итог»), `site-cs-check` 0, `site-cs-strict-types` 0, `site-test-unit` 4383 OK, `site-test` 7932 — одно падение исправлено
- [x] гейты Go: `fetchkit`, `ozon-fetcher`, `wb-fetcher` — `go vet`, `golangci-lint` 0, `go test -race` с PG15
- [x] внешнее ревью (Codex): site — REVIEW_GREEN; services — раунд 1: 1 BLOCKER + 2 IMPORTANT исправлены (d84d68ffd), раунд 2 — REVIEW_GREEN
- [x] `ARCHITECTURE.md` (2.117–2.121), `service-gateway.md`, `services/README.md`, README и AGENTS сервиса, контракты `service-coverage` v1.1.0 и `wb-finance-quota` v1
- [x] PR #2861, «merge and deploy» 03.11 — merge d7c624ee5, деплой зелёный; все кабинеты — 03.11 (решение Владельца)
- [ ] после деплоя: проверки этапа 5 — `healthy` и failed-очередь 0 сделаны 03.11; ночь и цифры «до/после» — 04.11

## Итог

- Go-сервис `services/wb-fetcher` на ядре `fetchkit` (вынесено из ozon-fetcher без изменения поведения):
  детальный отчёт о реализации WB за день и за ISO-неделю → raw S3 `wb/…`; неделя — для сверки с днями.
- Лимит WB общий с импортом Symfony: слот в лимитере Symfony перед каждым запросом
  (`/internal/wb-finance/sales-reports/slot`), 429 — общий cooldown; без ответа квоты запросов нет.
- Админка «Покрытие Сервис GO»: вкладки Ozon | Wildberries, недельные ячейки.
- Прод: сервис за `vars.WB_FETCHER_ENABLED`, кабинеты — `vars.WB_FETCHER_CONNECTION_ALLOWLIST`
  (эталон — «Вумджой ООО»); инструкция — `docs/maintenance/wb-fetcher-prod.md`.
- Загрузка WB в Symfony не меняется; откат — `WB_FETCHER_ENABLED` ≠ `true` и деплой.
- Полный PHPStan локально показывает только «unmatched ignore» baseline в файлах, которых ветка не
  касается (Cash, MarketplaceAds…); master на merge-base 62dda3879 в CI зелёный — решает CI PR.

## Нужно от Владельца до деплоя

Секреты `WB_FETCHER_API_TOKEN`, `WB_FETCHER_DB_PASSWORD` (hex-48), по желанию `WB_FETCHER_SENTRY_DSN`;
переменные `WB_FETCHER_CONNECTION_ALLOWLIST=19621cff-b028-45d9-9193-11f47ad9a8b2`, `WB_FETCHER_ENABLED=true`.
Без них деплой проходит, сервис остаётся выключенным.

## Открытые вопросы

- Расчёт сверки дневных отчётов с недельным по raw — потребитель raw, отдельная задача.
- ~~Symfony останавливает пагинацию WB на странице `< limit`~~ — исправлено задачей `docs/tasks/wb-finance-symfony-pagination` (03.11).
