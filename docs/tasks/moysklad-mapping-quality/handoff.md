# moysklad-mapping-quality — handoff

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
- Baseline222tests/727assertions; module Stage2 456/1770 и Stage3 461/1803 GREEN; обязательные регрессии TASK RED→GREEN.
- Полный PHPStan level8/rules/baseline, CS/strict-types/unit/integration/all functional shards, migrations-empty-db, APItypes, Twig и SPA isolation GREEN в CI на8fe0242ea. Полные стандартные гейты final application code5186be2af также GREEN: CI unit4928/27251, integration3037/14508, functional1497/11504. Merge ref включает upstream master. Финальный source run43844225493 завершился success, включая SPA isolation. Для documentation-only commit GitHub не создал новый run; git diff --check GREEN, application tree идентично проверенному5186be2af. Live Ready/status — PR3032.
- Последние external fixes13tests/127assertions GREEN: scoped cleanup, stale/retry, no duplicate running producer, unrecoverable conflict, read/write+CSRF. Focused PHPStan GREEN, fixer0/5.
- Unit4921tests/27234assertions GREEN. После freeze с COMPOSER_PROCESS_TIMEOUT=0 все3 локальных набора GREEN: unit4921/27234, integration3036/14500, functional1494/11475; суммарно9451tests/53209assertions. Результаты Stage5/PR.
- Локальные full make stan/test не зелёные: incomplete workers/SIGKILL137; единый test process не завершился, split integration сначала превысил Composer300s. Не скрываются и не выдаются за успешные. Финальные полные CI suite запускаются раздельно; локальный повтор изменяет только process/timeout environment, не тесты/гейты.
- Twig no violations/402 valid templates; frontend lint/build/unit152 GREEN. Browser report/manual1280/390 без overflow, screenshot inspection (HTML от функциональных HTTP-тестов, реальные CSS/fonts, scripts/live requests blocked); это не end-to-end проверка queue/JS.
- Миграции/SchemaInSync на isolated DB GREEN; production не использовался.

## Ревью
Fresh internal полного diff: BLOCKER0, IMPORTANT1 (повтор полного fingerprint в каждом batch), FOLLOW-UP1 (полный fingerprint при GET); IMPORTANT исправлен RED7→GREEN2 полных hash для205строк/3пакетов; drift раннего пакета→stale. Дополнительно CI нашёл отсутствие WRITE-гейта producer: исправлен,11tests/116assertions GREEN. Открытых BLOCKER/IMPORTANT нет. Финальное external Claude1 round: IMPORTANT1 рост detailed report rows и MINOR2 конфликт runId/test artifacts исправлены RED5failures→GREEN13tests/127assertions, fixed without re-run. BLOCKER0. Headers сохраняются; новый completed атомарно заменяет detail rows в своём scope; stale чистит свои rows; failed с утраченными rows становится stale и не возобновляет cursor. Реальный retry несуперседированного failed продолжает пакет.

External всего4 завершённых раунда: Stage2 Claude2 (IMPORTANT4 fixed without re-run); Stage3 Claude529 unavailable→fresh read-only Codex1 REVIEW_GREEN; handoff Claude1 (IMPORTANT1/MINOR2 fixed without re-run). Ограничения: финальный Claude читал diff/окружающие файлы, shell denied, независимых запусков тестов не делал. Новые вызовы после IMPORTANT не требуются§7.2. Stage2 совет продолжать с закрытым EM отклонён по transaction contract; batch безопасно оставляет остаток NOT_PROCESSED.

Метрики найдено/исправлено: внутренние BLOCKER1/IMPORTANT7 (включая CI gate); внешние BLOCKER0/IMPORTANT5. Открытых BLOCKER/IMPORTANT нет. Fresh final independent review отдельно:0B/1I fixed,1FOLLOW-UP.

## Ограничения / решения
- WB preset выключен по умолчанию; null barcode означает необходимость нормального reimport.
- Показатели35/45/26 — исходная оценка. Production coverage не пересчитывалось и автоматическое назначение всех кандидатов не обещается.
- Connection-wide hash assignment/review может консервативно пометить отчёт другого marketplace устаревшим.
- FOLLOW-UP: замерить O(N) current fingerprint при GET/фильтрации/пагинации на реалистичном объёме; при необходимости дешёвая версия изменений. В этой задаче не вводится инфраструктура tracking.
- Detailed rows хранятся для последнего completed и текущего retryable расчёта до замены; заголовки истории и отдельный audit package confirmations не удаляются. Исторические подробности заменённых отчётов недоступны; исходные назначения и финансовые данные не затрагиваются. Content fingerprint не даёт строгой snapshot isolation/гарантии обнаружения ABA(change→revert); диагноз не авторизует запись, защищённый writer повторно проверяет данные.
- Повторный поиск дублей для уже назначенных строк не добавлялся: назначения сохраняются, доступность и комплектация проверяются. Автоисправление и множители исключены TASK.

## Решение владельца
После зелёных финальных проверок: PR добавляет3 аддитивные миграции с необратимым down; требуется штатный backup, откат кода без удаления схемы. Merge, production migrations и deploy одним согласованием: `run the migration and deploy #3032`.
