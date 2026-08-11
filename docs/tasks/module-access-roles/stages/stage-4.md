## Stage 4: Write-гейты marketplace — DONE

**Риск:** 🟠 HIGH-LOCAL (авторизация)
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Следующее действие:** continue autonomously к Stage 5 (сайдбары и дашборд)

### Scope Stage

- Stage base commit: `292f2340`
- Work items completed: `4.1`, `4.2`, `4.3` (`4.4` снят в Stage 3 — master удалил `DebugWipeCompanyDataController`)

### Что сделано

**4.1 — инвентаризация.** Карта роутов снята из `debug:router --format=json`, а не грепом: `methods:
['GET', 'POST']` и многострочные `#[Route]` грепом разбираются неверно (первая попытка дала неверную
классификацию). 157 marketplace-роутов вне exempt-подпапок, из них 80 мутирующих или без явного
`methods`. Десять роутов с `ANY` проверены поимённо — все read-страницы.

**4.2 — 64 гейта `MARKETPLACE_WRITE`:** 59 атрибутом на POST/PUT/PATCH/DELETE-экшенах, 5 рантайм-проверкой
на смешанных `GET+POST`. `DebugRevenueReconciliationController` удаляет дубли `marketplace_returns`
и имеет роут `GET|POST`; проверено, что удаление требует `cleanup=1 && confirm=1 && POST`, поэтому
POST-гейта достаточно.

**4.3 — два уровня проверки покрытия:**

- `ModuleWriteGateCoverageTest` (integration) — статический инвариант по скомпилированной
  `RouteCollection`. Мутирующий маршрут без гейта своего модуля роняет тест.
- `ModuleMixedRouteGateTest` (functional) — поведенческое доказательство по всем 42 смешанным
  маршрутам: участнику с `<module>:read` GET не отдаёт 403, POST отдаёт 403 (или 404 там, где
  сущности нет либо сработал биллинговый флаг).

Плюс группа marketplace добавлена в матрицу `ModuleWriteGateTest`.

### Что нашли собственные инварианты

Статический инвариант сразу вскрыл **десять** пропусков Stage 3, все закрыты:

- `CashTransferController::new/delete/restore` — модуль мультивалютных переводов приехал с master
  уже после того, как был написан черновик гейтов, поэтому мимо расстановки;
- `CashTransactionBulkDeleteController` — массовое soft-удаление транзакций, тоже с master;
- `BalanceStructureController::move/linkMoneyAccountsTotal/linkMoneyFundsTotal` — в файле был
  закрыт только `delete`;
- `CostsDebugController::reconcilePage` — данные не меняет, но POST грузит файл и запускает разбор,
  поэтому закрыт как запись;
- `app_ai_suggestions_index`, `finance_report_pl_raw` — read-страницы без явного `methods`.

Двенадцати read-страницам проставлен `methods: ['GET']`: теперь read-only держит роутер, а не список
исключений в тесте.

### Затронутые файлы

- 37 контроллеров Marketplace/MarketplaceAds/MarketplaceAnalytics/Inventory/MoySklad — гейты
- `site/src/Cash/Controller/Transfer/CashTransferController.php`,
  `site/src/Cash/Controller/Transaction/CashTransactionBulkDeleteController.php`,
  `site/src/Balance/Controller/BalanceStructureController.php` — закрытые пропуски Stage 3
- `site/src/Inventory/Controller/{Snapshot,WbSnapshot}RequestController.php`,
  `site/src/MarketplaceAds/Controller/{ExtractBatches,Api/OzonAdLoadRange}Controller.php`,
  `site/src/MarketplaceAnalytics/Controller/Api/MarketplaceAnalyticsController.php` — BLOCKER-фикс
- `site/tests/Integration/Company/Security/ModuleWriteGateCoverageTest.php` — new
- `site/tests/Functional/Company/ModuleMixedRouteGateTest.php` — new
- `site/tests/Functional/Company/ModuleWriteGateTest.php` — modified
- `ARCHITECTURE.md` — версия 1.78, покрытие гейтов и правило fail-closed по `methods`

### Проверки

- `make site-test-unit` — OK (1874 tests, 10765 assertions)
- `composer test:functional` — OK (489 tests, 2864 assertions)
- `composer test:integration` — OK (967 tests, 4494 assertions)
- `php-cs-fixer` точечно по файлам Stage 4 — чисто (репозиторный `cs:check` красный по baseline)
- Регрессия доказана красным дважды: со снятым гейтом в `MarketplaceCostCategoryController::create`
  и в `DealController::new` падают соответствующие кейсы `ModuleWriteGateTest`; при подмене
  inline-гейта в `CashTransferController::new` на атрибут `ModuleMixedRouteGateTest` ловит
  «GET отдал 403 участнику с finance:read»

### Внешнее ревью

- Reviewer: Codex CLI 0.147.0
- Итераций: **8**
- Результат: **REVIEW_GREEN**
- Итого находок: 1 BLOCKER, 16 IMPORTANT, 6 MINOR. Все подтверждены и исправлены, кроме одного
  частичного отклонения с обоснованием (см. ниже).
- Ограничения ревьюера: локальная песочница Codex не запускалась (`bwrap: loopback: Failed
  RTM_NEWADDR`), поэтому дифф, контекст и результаты прогонов передавались в промпте через stdin.
  Ревьюер не мог самостоятельно проверить Git-статус и прогоны — эти факты приняты с моих слов.

#### BLOCKER: `ROLE_COMPANY_OWNER` не является tenant-scoped гейтом

Пять marketplace-контроллеров стояли только под `#[IsGranted('ROLE_COMPANY_OWNER')]`, и
инвентаризация Stage 4 не тронула их, считая owner-only гейтом строже модульного. Премисса неверна:
`role_hierarchy` объявляет роль глобально, её ставит `CompanyOwnerAccountCreator` на сущность `User`
при регистрации. Она означает «зарегистрирован как владелец компании», а не «владелец активной
компании». Владелец компании A, будучи read-only участником компании B, проходил гейт и мог
запускать в компании B загрузки и пересчёты.

Закрыто: пять контроллеров получили `MARKETPLACE_WRITE`, глобальная роль оставлена дополнительным
coarse-гейтом; роль убрана из списка «строгих» в инварианте, чтобы её больше нельзя было принять
за замену; `ARCHITECTURE.md` объясняет, почему это не так.

#### Частичное отклонение с обоснованием

Требование перевести все 42 смешанных маршрута на fixtures и требовать ровно 403 принято частично.
Усилено там, где доказуемо: для маршрута без параметров пути 404 не принимается — сущность не
искалась, значит гейт обязан отдать 403; единственное послабление названо поимённо
(`finance_funds_new`, где `assertFeatureEnabled()` срабатывает раньше гейта). Для 13
параметризованных маршрутов остаются 403 либо 404: оба означают, что путь записи не выполнился,
а 200/302/422/5xx роняет тест. Перевод на fixtures — follow-up.

#### Чему научили итерации

Шесть из восьми итераций касались не гейтов, а самого инварианта покрытия. Он переписывался трижды,
и каждый раз ревью находило реальный fail-open путь: проверка по атрибутам метода не видела
invokable-контроллеров с class-level `#[Route]`; ожидаемый модуль отбрасывался, поэтому
marketplace-экшен с `FINANCE_WRITE` прошёл бы; `str_contains` по исходнику считал гейтом
закомментированный вызов; `->isOwner(` — boolean-предикат — считался fail-closed проверкой;
allowlist read-страниц по именам был вечным исключением; `methods: []` считался мутирующим, но не
читающим. Вывод для следующих этапов: тест, который охраняет права, сам нуждается в ревью не меньше
охраняемого кода, а статику стоит подпирать поведенческой проверкой там, где статика доказать не может.

### Риски / на что обратить внимание ревьюеру

- Пять контроллеров, получивших `MARKETPLACE_WRITE` в BLOCKER-фиксе, раньше пропускали любого
  пользователя с глобальной ролью владельца. После правки владелец активной компании проходит
  по-прежнему (resolver даёт владельцу write на все модули), а владелец чужой компании — нет.
  Это осознанное изменение поведения.
- `methods: ['GET']` проставлен двенадцати read-страницам: POST на них теперь 405 вместо рендера.
- Stage 5 не сделан: меню не скрывается, участник с ограниченным шаблоном видит все пункты
  и получает 403 по клику.

### Follow-ups (вне scope Stage 4)

- Перевести 13 параметризованных смешанных маршрутов на fixtures, чтобы требовать ровно 403.
- Прогонять поведенческий тест по каждому разрешённому мутирующему методу, а не только POST.
- Follow-ups Stage 3 остаются открытыми (см. `stage-3.md`): удаление компании с участниками,
  валидация UUID на write-путях, `CompanyController::setActive` для участника, порядок трейтов
  в `CashCategoryUpsertTool`, ложный `DROP INDEX` при `migrations:diff`.

### Открытые вопросы

нет
