# Handoff: маппинг листингов на МойСклад

**Статус:** реализация и review завершены; ожидается разрешение на выпуск.
Merge, production migration и deploy не выполнялись.
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3016
**Ветка:** `feat/moysklad-listing-mapping`, base `master`.
**Task base:** `e0b5ac0d276ccb58d38c069722a2fad90358edda`.

## Результат и файлы

Отдельные legacy-вкладки Ozon/Wildberries в модуле МойСклад: выбор подключения,
постраничные листинги, локальный поиск/подсказки, ручное назначение product или
конкретного variant, снятие связи и неизменяемая история snapshots.

- `site/src/Marketplace/{Facade,DTO,Infrastructure/Query}` — scoped read API;
  существующий Catalog mapping не используется и не меняется.
- `site/src/MoySklad/{Entity,Application,Infrastructure,Controller,Form}` —
  журнал ревизий, ограничения компании/подключения, CAS, replay, locks и UI.
- `site/templates/moy_sklad/{layout.html.twig,mappings/}` — Twig/Tabler,
  существующие tabs/pagination/confirm partials, noscript снятие связи.
- `site/migrations/Version20261115153000.php` — новая пустая таблица истории,
  FK/CHECK/unique/индексы; существующие данные не преобразуются.
- `site/tests/{Unit,Integration,Functional,Builders,Fixtures,Support}` —
  invariants/storage/tenant/HTTP/реальные PostgreSQL-процессы и fixtures.
- `ARCHITECTURE.md`, TASK/plan/checkpoint и четыре Stage Report.

Finance, Inventory, FIFO, отчёт комиссионера, продажи/возвраты, HTTP-запись в
МойСклад, фоновые задачи и React/SPA вне задачи. Новых зависимостей нет.

## Проверки

- Baseline до реализации: unit 4807/26743, scoped integration 112/514 PASS.
- Тесты сначала: отсутствующие Facade/Entity/Action/routes дали RED;
  исправления review воспроизведены RED перед исправлением.
- Итоговый module/related suite: 394 tests, 1662 assertions PASS.
- Последний MINOR Claude, blank search: RED на SQL LIKE для пробельной строки;
  весь MarketplaceFacade suite GREEN 23 tests, 69 assertions (дополнительный
  тест добавлен уже после старта полного прогона).
- Полный unit gate: 4819 tests, 26765 assertions PASS.
- Полный PHP style/strict-types PASS; после review focused CS по 36 PHP PASS.
- Full PHPStan: 30 ignore.unmatched в 10 посторонних файлах; файлы и baseline
  совпадают с task base. Ошибок типов изменённого кода нет. Focused PHPStan
  всех 35 task PHP-файлов PASS; baseline не переписывался.
- make site-test первый запуск прерван exit137 около 90%, не объявлен зелёным;
  наблюдалось давление памяти, Docker OOM event не найден. Повтор всего
  набора на отдельной task-only БД: 9254 tests / 51773 assertions, 1 ERROR —
  таймаут 5 секунд в неизменённом ProcessOzonRealizationConcurrencyTest
  (reprocessing). Повтор всего этого класса без конкурирующей нагрузки:
  4 tests / 42 assertions PASS, 9.112 s. Новая логика маппинга не падала.
  CI итогового commit проверяется отдельно в PR; локальный full run не назван
  полностью зелёным.
- make site-cs-twig PASS: twigcs + syntax всех 407 Twig-шаблонов.
- Migration up/down/up на пустой БД PASS, непустой down запрещён;
  queued SQL guard и оба порядка down/конкурентная запись PASS.
- Реальные Action/import races: variant INSERT и parent archive в обеих
  очередностях, observed lock waits. Mutation без Action lock: RED 2/2,
  восстановленный код GREEN. Статистика workers обновляется явно.
- Chromium desktop1440/mobile375: Ozon product, WB variant/размер, история,
  клавиатура/radio, длинные названия, JS confirm и no-JS clear PASS;
  0 JS errors, нет горизонтального переполнения страницы на mobile.
- git diff --check PASS.

Окружение изолировано от dev/production: собственные контейнеры/БД/сеть,
никаких production credentials или live API side effects. Для disposable
PostgreSQL отключена только durability тестовых данных, чтобы уменьшить
время reset; транзакции и блокировки настоящие. Браузер использовал test
Symfony с явным наследованием task env и отдельную smoke БД; auth/CSRF/права
дополнительно проверялись функциональными тестами. JS/CSS не менялись,
поэтому npm build/lint не требовались.

## Review и метрики

- Stage 1 internal B/I 0/0; external не требовалось.
- Stage 2 Claude CLI: 1 раунд REVIEW_GREEN, B/I 0/0, MINOR 3 исправлены.
- Stage 3 Claude CLI: 1 раунд, B/I 0/1, MINOR 4 исправлены и проверены,
  fixed without re-run; BLOCKER не было.
- Final internal: свежий gpt-6-astra, spec + полный diff + checklist,
  B/I 1/2, MINOR 1; всё исправлено и проверено. Down race закрыт атомарным
  DO с ACCESS EXCLUSIVE/recheck/DROP; HTTP replay не зависит от новых choices;
  добавлены полные Action/import races и явные SQL-колонки.
- Final external Claude CLI: 1 раунд REVIEW_GREEN, B/I 0/0, MINOR 2.
  Blank-search исправлен и проверен без повторного раунда. Совет hoist
  двух status calls отклонён: entityType различается, число запросов от
  переноса в переменные не меняется; Query не поддерживает batch типов.
- Review metrics: internal B/I found 1/2, open 0/0; external B/I found 0/1,
  open 0/0; external implementation rounds 3 (Stage2 + Stage3 + handoff).

Ограничения: ревьюеры только читали, тесты запускал исполнитель; внешний
скрипт исключает Fixtures, поэтому worker явно передан reviewer отдельным
read-only pointer. Плановое review.md относится к PR #3015 и не подменяет
ревью реализации. Production и будущая финансовая семантика не проверялись.

## Решения и цена ошибки

| Решение | Основание; цена ошибки |
|---|---|
| Stage/checkpoint вместо skill Task scripts | План размечен Stage, скрипты не исполняются; риск пропуска шага закрывается reports/ledger и тестовыми выводами |
| Facade определяет out-of-range через count/nbPages | Тот же контракт пустой страницы; конкурентное изменение каталога может дать пустую страницу чтения |
| expected_revision хранится в журнале | Нужен точный replay исходного payload; неверная версия отклоняется CHECK/CAS, существующие строки не переписываются |
| Три public roots только services_test.yaml | Нужны integration/worker до UI; production visibility не меняется, DI дополнительно проверен functional/browser |
| Typed UUID IN для batch lookup | Сохраняет индексный поиск без конкатенации; возможное удорожание ограничено 100 ключами |
| Не hoist разные sync-status calls ради мнимого сокращения SQL | Никакого сокращения нет; возможная будущая оптимизация batching влияет на latency, не на корректность |

Отложенных подтверждённых MINOR нет. Новые бизнес-правила не добавлялись.

## Выпуск и откат

Миграция `Version20261115153000` создаёт только новую пустую таблицу,
без backfill и без изменения существующих финансовых/товарных данных.
Down допустим только пока история пуста; эксклюзивная блокировка и повторная
проверка защищают даже от первой конкурентной записи. При наличии истории
нужна forward migration, удаление данных откатом запрещено.

Требуется одно решение владельца по docs/workflow/release.md:

```text
run the migration and deploy #3016
```

После решения: Ready → merge → CI; dispatch production migrations, проверить
схему, затем dispatch deploy и read-only post-deploy acceptance. Не выполнять
пробные production назначения и внешние API-записи в рамках приёмки.
До этого решения ни merge, ни production migration/dispatch/deploy не разрешены.
