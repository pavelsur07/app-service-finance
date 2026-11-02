### Stage 4: покрытие нескольких сервисов, вкладки Ozon | Wildberries — TODO

**Risk:** MEDIUM · **Branch:** `feat/service-coverage-wb-tab`
**Внешнее ревью:** нет (на handoff)

## 1. Какую задачу решаем

`ServiceGateway` знает один сервис: `OzonFetcherCoverageClient`, команда обновления
берёт один клиент, страница — `/admin/services/ozon-fetcher/coverage`. Нужны
витрина и страница для wb-fetcher во вкладке рядом с Ozon.

## 2. Что будет результатом работы

- Клиент `WbFetcherCoverageClient` (env `WB_FETCHER_BASE_URL`, `WB_FETCHER_API_TOKEN`);
  общая часть разбора контракта v1 — без копии (базовый клиент или композиция).
- Команда `app:service-gateway:coverage:refresh` обходит все клиенты (tagged iterator);
  сбой одного сервиса не мешает другому; lock — на сервис; выключенный (пустые env) —
  INFO и пропуск.
- `CoverageHeatmapBuilder`: строка недельного ресурса — ячейки на недели, пересекающие месяц.
- Страница `/admin/services/coverage?service=ozon-fetcher|wb-fetcher&month=YYYY-MM`,
  вкладки **Ozon | Wildberries** (компонент вкладок дизайн-системы), месяц сохраняется
  при переключении вкладки; старый URL `/admin/services/ozon-fetcher/coverage` → 301 на новый;
  неизвестный `service` → 404. Пункт меню «Покрытие Сервис GO» ведёт на новую страницу.
- Названия ресурсов WB для строк: «Отчёт о реализации — день», «Отчёт о реализации — неделя».
- `ARCHITECTURE.md` (ServiceGateway, Cron), `service-gateway.md` §4.

## 3. Что считаем выполненной работой

Команда наполняет витрину обоих сервисов; падение одного не трогает строки
другого; вкладка Wildberries показывает дневную и недельную строки; вкладка Ozon
выглядит как раньше.

## 4. TDD — тесты пишутся первыми

- [ ] unit клиента WB на `MockHttpClient`: успех, 401, 5xx, таймаут, нарушение контракта, выключен; фикстура с `YYYY-Www`
- [ ] integration команды: оба сервиса → витрина обоих; WB 5xx → строки WB прежние, Ozon обновлён, WARNING; WB выключен → INFO
- [ ] integration Action: замена строк одного сервиса не удаляет строки другого
- [ ] unit `CoverageHeatmapBuilder`: недельная строка в ноябре 2026 — недели W44…W49, ячейка W44 частично вне месяца; цвета ячеек недели по тем же правилам
- [ ] functional: `/admin/services/coverage?service=wb-fetcher` — 200 админу, 403 пользователю; `service=foo` → 404; месяц вне окна → redirect с flash
- [ ] functional: старый URL → 301 на `?service=ozon-fetcher`
- [ ] functional: активная вкладка и ссылка второй вкладки с тем же `month`
- [ ] functional: данные из витрины WB попадают в HTML (подписи `3/4`, неделя)

## 5. Чек-лист приёмки

- [ ] тесты §4 были красными до кода
- [ ] env в `.env` (пусто), `.env.test` (значения тестов)
- [ ] Query без N+1, одна выборка на сервис-месяц
- [ ] визуальная проверка на локальном стенде обеих вкладок (память «Локальная визуальная проверка»), скриншоты в PR
- [ ] дизайн-система: вкладки, цвета ячеек — токены, без хардкода (`CLAUDE.frontend.md`)
- [ ] `ARCHITECTURE.md`, `service-gateway.md` §4
- [ ] PHPStan / CS / strict_types по изменённым файлам; baseline не растёт
