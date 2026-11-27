# Stage 2: ИИ-задача

**Risk:** HIGH-LOCAL — миграция, новая задача `ai-service`, триггер в Messenger handler (без изменения routing).

## 1. Задача
Склады, которые не разобрали правила (11 из 36 у эталона), получают предложение ИИ; уверенные входят в «Принять все».

## 2. Результат
- `ai-service`: `tasks/moysklad_store_account_suggest` (schemas, checks `account_known`/`single_decision` — BLOCK,
  prompt v1, risk medium, порог 0.8), маршрут `deepseek-flash → gpt-4.1-mini`, stub `store-account`,
  eval-набор 25 кейсов (7 ловушек «не знаю»), контракт `ai-task/v1` v1.4.0 + копии примеров в `site/tests/Fixtures`.
- Symfony: `moysklad_store_account_suggestions` (`Version20261127150000`), `StoreAccountSuggestionStore`,
  `RequestStoreAccountSuggestionsAction` (вызов из `SyncStockSnapshotHandler` после снимка),
  `StoreAccountSuggestionConsumer`, ответы ИИ в `StoreAccountSuggestions`, бейдж «ИИ» / «проверьте».
- `FakeAiTasks` вынесен в `tests/Support/ServiceGateway` (был внутри теста Ozon).

## 3. Выполнено, когда
Код вне списка отвергается, «не знаю» не бывает уверенным, ответ на старое название не показывается,
сбой/выключенный ИИ не оставляет строк и повторяется; тесты и гейты зелёные.

## 4. Чек-лист приёмки
- [x] ai-service: ruff, format, mypy — OK; pytest not evals — 192 OK (integration без БД пропущены, гоняет CI); evals — 65 OK.
- [x] Контракт: примеры валидны, копии в Symfony совпадают (`test_contract.py`), версия промпта Symfony = сервиса.
- [x] Integration `StoreAccountAiSuggestionTest`: постановка только складов без правила, примеры компании, только счета
      актива; уверенный ИИ + правило → «Принять все» 2, неуверенный не принят; переименование → новый вопрос;
      «не знаю», rejected и чужая задача — без предложения; выключен/сбой → 0 строк, зависший запрос — повтор тем же ключом.
- [x] `tests/Unit|Integration|Functional/MoySklad`, `Architecture`, `MarketplaceOzon`, `ServiceGateway`,
      `SchemaInSyncWithMigrationsTest`, `MessengerRoutingTest` — 778 OK.
- [x] PHPStan по затронутым каталогам — OK; CS без кеша; twigcs, lint:twig — OK.
- [ ] Живой прогон evals (`evals-compare`) — только с app-сервера через шлюз: после деплоя, порог ≥ 90 % верных среди уверенных.

## Итог
Триггер в handler не покрыт отдельным тестом: вызов best-effort за `catch (\Throwable)` с `error` в лог; путь
сбоя синхронизации покрыт существующими тестами handler с новым аргументом.
