### Stage 1: схема и tenant-scoped хранение — CLOSED

**Risk:** HIGH-LOCAL
**Stage base commit:** `2d23ac36f9c6504743460fc064406a5088b7464f`
**Work items:** 1.1, 1.2

#### What was done

- Добавлены `MoySkladCounterparty`, `MoySkladSyncCursor`, `MoySkladSyncRun`, нормализованный снимок контрагента, tenant-scoped репозитории и тестовые builder'ы.
- Миграция создаёт три таблицы, FK без каскада, уникальные и поисковые индексы, ограничения статуса и счётчиков. Удаление подключения с данными отклоняется существующим `connection_in_use`.
- Контракт сущностей описан в `ARCHITECTURE.md`; токен и произвольный API payload не хранятся.

#### Definition of Done

- [x] Поля и ключи соответствуют Stage 0.
- [x] Изоляция компаний проверена запросами по внутреннему и внешнему ID.
- [x] FK запрещают каскадное удаление подключения.
- [x] Новые таблицы создаются на пустой test-БД; уникальность `(connection_id, external_id)` проверена.

#### Checks

- baseline: `tests/Unit/MoySklad` — 46 tests / 227 assertions; `tests/Integration/MoySklad` — 16 / 60.
- `make site-test-db-rebuild` с изолированным Compose override — PASS, 262 migrations.
- модульные unit+integration после исправлений — 81 tests / 346 assertions, PASS.
- focused PHPStan — no errors; focused CS Fixer — 0 fixable files; Doctrine metadata mapping — valid.
- полный `doctrine:schema:validate` — red: глобальный diff содержит сотни старых расхождений и намеренные ручные FK. Структура новых таблиц и индексов проверена прямым запросом к локальной test-БД.

#### Internal review

- Iterations: 1. BLOCKER: 0. IMPORTANT: 1, fixed — добавлен ведущий `connection_id` индекс к истории запусков для проверки FK. MINOR: 1, fixed — ограничены индексы builder'ов. Открытых BLOCKER/IMPORTANT нет.

#### External review

- Required: yes, HIGH-LOCAL Stage с diff более 500 строк. Round 1: 0 BLOCKER, 2 IMPORTANT, 4 MINOR. Обе IMPORTANT исправлены: UTC microsecond type сохраняет момент и точность; `down()` удаляет только пустые таблицы. MINOR: унифицирован порядок параметров репозитория, разрешено имя `0`, добавлен частичный unique index для `running`. Замечание об uppercase UUID отклонено: тест PostgreSQL подтверждает корректный поиск. После фиксов: 81 тест / 346 assertions, focused PHPStan/CS — PASS. Итог по политике: **fixed without re-run**.

#### Risks / reviewer focus

- `down()` работает на пустых таблицах и отказывает, если уже загружены данные. Production dispatch требует отдельного решения Владельца.
- `company_id` сверяется с компанией подключения в Action будущего Stage 3; FK удерживает подключение, а все запросы уже tenant-scoped.

#### Next

- Продолжить Stage 2 автоматически после внешнего review и commit/push.
