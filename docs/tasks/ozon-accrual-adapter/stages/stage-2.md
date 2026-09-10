### Stage 2: справочник type_id → категория затрат через Facade — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `cc5071d8b9f4ba8d3b42d6c4f4204d984f93420b`
**Work items:** 2.1, 2.2

#### What was done
- `OzonAccrualCategoryFacade` — единственная точка, через которую другие модули
  разбирают услуги Ozon accrual в категории затрат. Read-only, без зависимостей.
- `OzonAccrualCategoryView` — тип контракта в `Application/DTO`, единственном
  подслое Application, открытом для других модулей.
- Разрешение идёт **только по имени** из справочника `/v1/finance/accrual/types`.
  Карта `typeIds` внутри `OzonAccrualCategory` намеренно не используется: сверка
  124 записей справочника с каталогом показала, что из 18 услуг выгрузки за июнь
  по имени разбираются 15, а все три случая, где сработала бы карта, дают
  неверную категорию (29 LastMileCourier, 45 PickUpPointReturnAcceptance,
  77 SupplyInbound). Правдоподобная чужая категория хуже честного «не разобрано».
- Неразобранная услуга деградирует в видимую синтетическую категорию с
  `known = false` и группой «Требует классификации» — очередь на ручной разбор,
  не NULL и не «прочее» (`docs/workflow/health-gates.md`).

#### Files changed
- `site/src/Ingestion/Facade/OzonAccrualCategoryFacade.php` — new
- `site/src/Ingestion/Application/DTO/OzonAccrualCategoryView.php` — new
- `site/tests/Unit/Ingestion/Facade/OzonAccrualCategoryFacadeTest.php` — new
- `ARCHITECTURE.md` — modified (новый Facade + запись 1.87 в журнал версий)
- `docs/tasks/ozon-accrual-adapter/plan.md` — modified (находка про карту typeIds)

#### Definition of Done
- [x] read-only метод Facade; `ARCHITECTURE.md` обновлён в том же Stage
- [x] контракт таков, что Marketplace не заведёт своей копии справочника
- [x] неизвестный `type_id` даёт видимую очередь, а не NULL и не «прочее»
- [x] unit-тесты: известная услуга, неизвестная, пустой вход
- [x] исключено: логика самого Ingestion, его загрузчики и таблицы не тронуты

#### Checks
- baseline: `make site-test-unit` — 2356 зелёных до Stage; 4 pre-existing deprecations
- targeted: `bin/phpunit tests/Unit/Ingestion/Facade/OzonAccrualCategoryFacadeTest.php`
  — 6 ошибок на отсутствующем классе до реализации, 6/6 зелёных после
- full: `make site-cs-check` 0/2500; `make site-cs-strict-types` 0/2500;
  `make site-stan` — **No errors**, baseline не вырос, правила границ модулей
  (`ModuleBoundaryRules`) исполняются как правила PHPStan и тоже зелёные;
  `make site-test-unit` — 2362 теста, 12104 утверждения

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none
- MINOR fixed: нет
- FOLLOW-UP: карта `typeIds` используется и внутри самого Ingestion при
  нормализации, поэтому `ingest_financial_transactions` может нести три
  названные услуги под чужими категориями. Правка Ingestion за рамками указания
  Владельца, зафиксировано в плане.

#### External review
- required: yes (HIGH-LOCAL)
- rounds: 1; result: fixed without re-run
- confirmed fixed: MINOR — из комментария теста убрано название кабинета
  клиента, чтобы идентифицирующие данные не попадали в историю репозитория
- rejected with reason: IMPORTANT по `site/bin/capture-wb-inventory.sh` —
  посторонний untracked-файл Владельца, попавший в дифф ревью. Это прямо описано
  в шапке `site/bin/external-review.sh`: сравнение идёт с рабочим деревом, и
  находки по посторонним untracked-файлам отклоняются как out-of-scope. По
  существу совет верен и выполнен: файл в коммит не попал, стадирование сделано
  поимённо, состав проверен `git diff --cached --stat`
- reviewer limitations: официальная документация Ozon недоступна, поэтому
  контракт справочника передан ревьюеру фактами из выгрузки

#### Risks / reviewer focus
- Разрешение по имени зависит от того, что Ozon не переименует коды справочника.
  Переименование не молчаливо: услуга уедет в очередь «Требует классификации»,
  а не в чужую категорию.
- Три неразобранные услуги (LastMileCourier, PickUpPointReturnAcceptance,
  SupplyInbound) требуют ручного сопоставления в Stage 4, когда появится
  потребитель.

#### Next
- continue to Stage 3 automatically
