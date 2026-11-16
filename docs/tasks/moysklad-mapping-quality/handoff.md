# moysklad-mapping-quality — handoff (проверка)

**Branch:** `feat/moysklad-mapping-quality` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/3032 · **Base:** master.

## Результат
Все шесть направлений TASK: комплектация, exact code, scoped WB alias, barcodes, диагностика кабинета и legacy UI. Источники не исправляются; существующие назначения сохраняются. Решения объединяют основания и не скрывают конфликт кандидатов. Спорная комплектация не проходит автоматически; ручная проверка хранится отдельно от назначения. Диагностика использует общий evaluator, durable batches≤100, не разрешает запись и не заменяет completed при partial/failed/stale.

## Файлы
- `site/src/MoySklad/` — importer/entities/DTO/policy/writer/query/handler/controllers/form.
- `site/src/Marketplace/{Facade,Infrastructure/Query,DTO}/` — scoped batch barcode/keyset/source-version contracts.
- `site/config/packages/messenger.yaml` — existing async_pipeline; новых worker/schedule нет.
- `site/templates/moy_sklad/mappings/`, shared `_pagination` — UI/evidence/filters/confirmation.
- `site/tests/` — unit/integration/concurrency/functional regressions.
- `site/migrations/Version2026111612*.php`, `ARCHITECTURE.md`, task reports.

## Миграции и release
- `Version20261116120000`: nullable barcode snapshots (null≠[]), scoped WB setting default off.
- `Version20261116121000`: отдельные revision/source/target-bound package confirmations.
- `Version20261116122000`: durable diagnostic runs/rows.
Все аддитивные: существующие назначения/данные не изменяются. `down` намеренно необратим для сохранения audit/barcodes. До production dispatch необходим штатный проверенный backup БД; при проблеме откатить application image без удаления аддитивных таблиц/колонок, исправлять схему только forward migration. Destructive rollback и восстановление backup не авторизованы автоматически. Порядок после единого согласования: merge → production_action=migrations → проверить schema gate → production_action=deploy → read-only acceptance. Production не использовался при разработке.

## Контракты
Новые internal Facade batch barcodes/keyset/version, identity_package_v2, revision-bound proof, сообщения диагностики/scoped POST legacy routes. Финансовые формулы/множители/public API marketplace не менялись. Nullable штрихкоды заполняются обычным повторным импортом.

## Проверки
Результаты полного последнего прогона и CI будут зафиксированы после завершения Stage5. До этого Ready не ставится. Baseline/RED→GREEN/module результаты — Stage1–4.

## Ревью
Fresh internal полного diff: BLOCKER0, IMPORTANT1 (повтор полного fingerprint в каждом batch), FOLLOW-UP1 (полный fingerprint при GET); IMPORTANT исправлен RED7→GREEN2 полных hash для205строк/3пакетов; drift раннего пакета→stale. Дополнительно CI нашёл отсутствие WRITE-гейта producer: исправлен,11tests/116assertions GREEN. Открытых BLOCKER/IMPORTANT нет. Прочие проходы и external — Stage2/3, финальное external ожидается после исправления.

## Ограничения / решения
- WB preset выключен по умолчанию; null barcode означает необходимость нормального reimport.
- Показатели35/45/26 — исходная оценка. Production coverage не пересчитывалось и автоматическое назначение всех кандидатов не обещается.
- Connection-wide hash assignment/review может консервативно пометить отчёт другого marketplace устаревшим.
- FOLLOW-UP: замерить O(N) current fingerprint при GET/фильтрации/пагинации на реалистичном объёме; при необходимости дешёвая версия изменений. В этой задаче не вводится инфраструктура tracking.
- Политика очистки audit/report не задана; новые данные не удаляются. Content fingerprint не даёт строгой snapshot isolation/гарантии обнаружения ABA(change→revert); диагноз не авторизует запись, защищённый writer повторно проверяет данные.
- Повторный поиск дублей для уже назначенных строк не добавлялся: назначения сохраняются, доступность и комплектация проверяются. Автоисправление и множители исключены TASK.

## Решение владельца
После зелёных финальных проверок: PR добавляет3 аддитивные миграции с необратимым down; требуется штатный backup, откат кода без удаления схемы. Merge, production migrations и deploy одним согласованием: `run the migration and deploy #3032`.
