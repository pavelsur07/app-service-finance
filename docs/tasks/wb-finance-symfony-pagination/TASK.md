# WB finance в Symfony: отчёт о реализации до пустой страницы — IN PROGRESS

**Risk:** MEDIUM (поведение внешнего импорта, без миграций) · **Ветка:** `fix/wb-finance-symfony-pagination`
**Решение Владельца 03.11.2026:** «делай пагинацию всё равно» — превентивно, дефект в данных не подтверждён.

## 1. Какую задачу решаем

Оба клиента WB `POST /api/finance/v1/sales-reports/detailed` в Symfony считают страницу
`< limit` последней. Документация WB (по внешнему ревью wb-fetcher 02.11) велит запрашивать
следующую страницу с `rrdId` последней строки, пока ответ не станет 204 или `[]`. Если WB
отдаст неполную страницу не в конце отчёта, хвост дня потеряется молча.

Замер прода 04.10–02.11 (`perf-report`, read-only): 846 запросов = 846 сообщений
`SyncWbFinancialReportDayMessage` = 846 `ProcessDayReportMessage`, 83 785 строк (≈ 100 на
страницу), пик памяти 38 МБ — вторых страниц не было, потери не видно.

## 2. Что будет результатом

| Что | Где |
|---|---|
| Следующая страница после каждой непустой (`hasNextPage = true`) | `Marketplace\Wildberries\Infrastructure\Api\WbFinanceSalesReportClient` |
| То же (`hasMore = true`) | `Ingestion\Infrastructure\Api\Wildberries\WbFinanceReportClient` |
| Регрессионные тесты: неполная страница не конец; конец — 204 / `[]` после данных | unit обоих клиентов, integration handler'а |
| Документация: Changelog, wb-api.md, handoff wb-fetcher | `ARCHITECTURE.md`, `services/wb-fetcher/docs/wb-api.md`, `docs/tasks/wb-fetcher-finance/handoff.md` |

Цена: +1 запрос (`[]`) на день и кабинет в каждом пути, пауза `wb_finance.rate_limit_interval_seconds`
(70 с) перед ним. Многостраничный путь handler'а и коннектора уже существует и покрыт тестами —
меняется только условие продолжения.

## 3. Проверки

До: замер выше. После деплоя (read-only `perf-report` за ночь): `SyncWbFinancialReportDayMessage`
≈ 2 × `ProcessDayReportMessage`, failed/retried 0, `api_fetch` errors 0.

## 4. Чек-лист приёмки

- [ ] оба клиента: непустая страница → продолжение; 204 / `[]` → конец
- [ ] handler: день из двух запросов (данные + `[]`) доходит до `ProcessDayReportMessage` один раз
- [ ] коннектор Ingestion: после `[]` курсор переходит к следующему дню
- [ ] unit + integration зелёные, PHPStan/CS по изменённым файлам 0
- [ ] после деплоя: perf-report за ночь без failed/retried
