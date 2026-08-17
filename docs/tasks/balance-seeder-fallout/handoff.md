# Handoff: починка регрессии после `15dabd37 refactor(balance)`

Ветка: `fix/balance-seeder-fallout`, база `master`, stage base commit `377c75d5`.
Один верхнеуровневый Stage — отчёт в `stages/stage-1.md`.

## Что было сломано

`15dabd37` удалил `App\Balance\Service\BalanceStructureSeeder`, но оставил две ссылки на него в
модуле Company. Symfony такой сервис не роняет при сборке контейнера, а помечает как errored и
бросает исключение в момент запроса, поэтому 500 получали только страницы, которым он нужен:

- `/finance/report/preview` — `AccountBootstrapper` не резолвился;
- `/company/new` — тот же класс в аргументах контроллера.

Попутно вскрылись два дефекта, которые бы вылезли сразу после починки DI:

- `AccountBootstrapper` использовал `Uuid::uuid4()` без `use Ramsey\Uuid\Uuid` — живой
  `ensurePlSeeded()` фаталил бы на первой созданной категории ОПиУ;
- `SeedBalanceStructureAction` создавал категории без `flush()`, а `LinkBalanceCategoryAction`
  ищет категорию через `findOneBy` (SQL) — линк падал `BalanceCategoryNotFoundException`, и две
  последние категории вообще не сохранялись. Тесты этого не ловили: интеграционного теста на seed
  нет, а unit-тесты ходят через `InMemoryBalanceCategoryRepository`.

## Изменённые публичные контракты

- **Добавлен** `BalanceFacade::seedDefaultStructure(string $companyId): bool` — описан в
  `ARCHITECTURE.md` (раздела про `BalanceFacade` там не было вообще).
- **Удалён** `AccountBootstrapper::bootstrapForUser(): ?Company` — вызовов в репозитории нет.
  Класс теперь зависит только от `EntityManagerInterface` и `PLCategoryRepository`.
- Поведение `SeedBalanceStructureAction::__invoke()` не изменилось по контракту, но теперь
  выполняется в собственной транзакции и реально сохраняет всё дерево.

## Миграции

Нет.

## Риски

- Удаление `bootstrapForUser()` необратимо для внешних вызовов вне репозитория; таких не найдено.
- Вложенные `wrapInTransaction` (контроллер → action) — штатное поведение DBAL, проверено прогоном
  functional-тестов, в том числе под `dama/doctrine-test-bundle`.
- `make site-cs-check` красный до задачи; на изменённых файлах три нарушения, все существовали ранее
  и не на изменённых строках.

## Follow-ups (вне scope)

- `php bin/console lint:container` в CI — ловит ровно этот класс поломок на сборке, а не в проде.
- Конкурентный `seedDefaultStructure` для одной пустой компании не сериализован (FOLLOW-UP внешнего
  ревью). В текущем маршруте companyId всегда новый, поэтому не воспроизводится.
- Локальный dev-стек этого чекаута не совпадает с тем, что обслуживает браузер разработчика:
  запущенные контейнеры принадлежат `/home/deploy/projects/vashfindir-site`. Правка доедет туда
  только после мержа и обновления того чекаута.
