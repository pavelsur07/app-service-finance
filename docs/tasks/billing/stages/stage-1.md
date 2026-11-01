# Этап 1 — модель и расчёт оплаченного периода

## Задача

Создать новый модуль `Billing` с минимальной моделью: тарифы с ценами за
срок, подписка компании, журнал оплат, расчёт периода «от даты оплаты» и
статуса доступа. Без UI и без ограничений доступа: этапы 2–4 строятся на
этом ядре.

## Результат

Второй коммит ветки задачи `feat/billing-core`.

**Конфигурация модуля:** mapping `Billing` в `doctrine.yaml`; `Billing` в
списке модулей `tests/Architecture/ModuleBoundaryRules.php`. Маршрутов,
шаблонов и сообщений на этом этапе нет.

**Enum**
- `TariffCode`: `start`, `base`, `pro`, `friend`, `support`, `outsourcer`.
- `PaymentSource`: `manual_invoice` (провайдеры — этап 5).
- `AccessStatus`: `active`, `grace`, `read_only`, `none` (подписки нет).

**Entity** (все `string $companyId` + `Assert::uuid`, UUIDv7, `DateTimeImmutable`)

| Entity | Таблица | Инварианты |
|---|---|---|
| `Tariff` | `billing_tariffs` | `code` unique; `isPublic`, `isPerpetual`, `isActive`, `sortOrder`, `name` |
| `TariffPrice` | `billing_tariff_prices` | `months ∈ {1,3,6,12}`, `amountKopecks > 0`; unique `(tariff_id, months)` |
| `CompanySubscription` | `billing_company_subscriptions` | unique `company_id`; `paidUntil = null` ⇔ бессрочный тариф; `paidFrom ≤ paidUntil`; `applyPayment(Tariff, PaidPeriod)`; `customMonthlyKopecks` только у Аутсорсера; `isTrial` |
| `BillingPayment` | `billing_payments` | `id` = ключ повтора от вызывающего; `months ≥ 1`, `amountKopecks ≥ 0`; `periodFrom ≤ periodUntil`; unique `(source, external_id)` (NULL не конфликтует); индекс `(company_id, received_at)`; методов изменения нет |

**Domain**
- `PaidPeriod` (VO: `from`, `until` — даты без времени).
- `PaidPeriodCalculator::next(?paidUntil, receivedAt, months)`: день
  зачисления — дата `receivedAt` в Europe/Moscow; начало = `paidUntil + 1`,
  если `paidUntil ≥ день зачисления`, иначе день зачисления; конец =
  начало + N мес − 1 день, а если числа начала нет в целевом месяце —
  последний день целевого месяца.
- `SubscriptionAccessPolicy::statusOn(?subscription-данные, today, graceDays)`:
  `none` / бессрочный → `active` / `today ≤ paidUntil` → `active` /
  `≤ paidUntil + graceDays` → `grace` / иначе `read_only`. `graceDays` —
  константа `SubscriptionAccessPolicy::GRACE_DAYS` = 5 (решение №3 ещё за Владельцем).

**Application**
- `RegisterPaymentAction(RegisterPaymentCommand)` — точка входа, сама
  коммитит. `wrapInTransaction()`: `pg_advisory_xact_lock` по компании (есть
  и при отсутствии строки подписки) → повтор по `id` → тот же результат →
  тариф по коду (активный, не бессрочный) → расчёт периода → оплата +
  создание/продление подписки. На `UniqueConstraintViolation` — поиск
  существующей оплаты DBAL после rollback по `id` или `(source,
  external_id)`; не найдено — пробросить.
- Правило тарифа: оплата того же тарифа продлевает; другого тарифа при
  истёкшей или бессрочной подписке — переводит с даты зачисления; другого
  тарифа при действующем платном периоде — `TariffChangeNotSupportedException`
  (перерасчёт остатка — этап 2).

**Facade** `BillingFacadeInterface` + `BillingFacade`:
`accessFor(companyId, today): BillingAccessDTO` (статус, код и название
тарифа, `paidUntil`, дней до окончания, `isTrial`).

**Миграция**: 4 таблицы, индексы, справочник 6 тарифов и 12 цен публичных
тарифов. Цены — по предложению (скидки 5/10/20%, округление до 10 ₽):

| | 1 мес | 3 мес | 6 мес | 12 мес |
|---|---|---|---|---|
| Старт | 4 970 | 14 160 | 26 840 | 47 710 |
| База | 9 950 | 28 360 | 53 730 | 95 520 |
| Профи | 14 790 | 42 150 | 79 870 | 141 980 |

Решение №1 за Владельцем; клиентам цены впервые показывает этап 3, до него
правятся отдельной миграцией без последствий.

**Документация**: раздел `Billing` в `ARCHITECTURE.md` (Entity, Facade,
кто коммитит, порядок блокировок), Changelog; `TASK.md` — статус.

## Что считаем выполненным

- Расчёт периода и статуса покрыт unit-тестами на все ветки.
- `RegisterPaymentAction`: happy-path, продление до и после окончания,
  повтор того же `id` → та же оплата и то же число строк, повтор того же
  `(source, external_id)` с новым `id` → исходная оплата, негатив (смена
  тарифа при действующем периоде, неизвестный/бессрочный тариф), ожидание
  блокировки компании, которую держит другое соединение.
- Facade — integration-тест на все статусы.
- Builders в `tests/Builders/Billing/`.
- Гейты зелёные, baseline не растёт; миграция up/down/up.

## Чек-лист приёмки

- [x] unit: `PaidPeriodCalculator` — 01.01→31.01, 15.01→14.02, 31.01→28.02, 30.01 (високосный 2028)→29.02, 3/12 мес, продление до окончания (с `paidUntil + 1`), после окончания (с даты зачисления), зачисление 23:30 UTC = следующий день МСК
- [x] unit: `SubscriptionAccessPolicy` — none / бессрочный / последний день / первый день льготного / последний день льготного / read_only
- [x] unit: инварианты Entity (`TariffPrice`, `CompanySubscription`, `BillingPayment`)
- [x] integration: `RegisterPaymentAction` — все сценарии из «выполнено»
- [x] integration: `BillingFacade::accessFor`
- [x] миграция up / down / up; справочник: 6 тарифов, 12 цен; `doctrine:schema:validate` без строк `billing`
- [x] `make site-stan` — No errors, baseline без роста
- [x] CS / strict_types по изменённым файлам
- [x] unit-набор и Architecture-тесты зелёные
- [x] IDOR: все методы репозиториев с `companyId` (или `@companyScopeExempt` для справочника тарифов)
- [x] `ARCHITECTURE.md`: раздел `Billing`, Changelog; `TASK.md` — статус этапа

## Итог

01.11.2026, коммит на ветке `feat/billing-core`.

**Сделано по плану**: 4 Entity, 3 Enum, `PaidPeriod` / `PaidPeriodCalculator` /
`SubscriptionAccessPolicy`, `RegisterPaymentAction`, `BillingFacadeInterface`,
миграция `Version20261101210000` (4 таблицы, CHECK-ограничения, 6 тарифов,
12 цен), Builders, раздел в `ARCHITECTURE.md`.

**Отличия от плана**
- Льготные дни — константа `GRACE_DAYS`, а не параметр контейнера: проще,
  меняется одной строкой.
- `customMonthlyKopecks` (Аутсорсер) и `isTrial` в подписку не добавлены —
  их первые потребители на этапах 2 и 3, колонки придут с ними. Из DTO Facade
  `isTrial` тоже убран.
- Добавлена фабрика `CompanySubscription::startPerpetual()` — нужна для
  ветки «бессрочный» в политике и для назначения «Друга» на этапе 2.
- `RegisterPaymentAction` и `BillingFacadeInterface` открыты в
  `config/services_test.yaml`: потребителей в коде пока нет, контейнер их
  вырезает.

**Проверки**
- unit Billing — 33 OK; integration Billing — 13 OK (вкл. ожидание
  блокировки, повтор по `id` и по `(source, external_id)`).
- unit-набор целиком — 4315 OK; Architecture + Company/Security — OK.
- PHPStan — No errors, baseline без изменений; CS и strict_types по 29
  файлам — чисто.
- Миграция up / down / up; справочник 6 + 12, суммы цен сверены с таблицей.
- `doctrine:schema:update --dump-sql`: по `billing` только FK на
  `companies`, которых нет в mapping (тот же паттерн, что у
  `company_tax_profiles`).
- IDOR: методы `CompanySubscriptionRepository` и `BillingPaymentRepository`
  принимают `companyId`; `TariffRepository::findOneByCode` —
  `@companyScopeExempt` (общий справочник).

**Ревью (01.11.2026)**
- Внутреннее (свежая сессия): BLOCKER нет; IMPORTANT — `received_at` без
  нормализации зоны. MINOR: исключение тарифа внутри транзакции закрывает EM;
  ветка `UniqueConstraintViolation` без теста; повтор с другим содержимым
  молча; пробелы веток `renewalBase`.
- Внешнее (Codex, `site/bin/external-review.sh 902770f28`): IMPORTANT —
  тот же `received_at`; устаревшая подписка из identity map после блокировки.
- Исправлено без повторного раунда (BLOCKER не было):
  `BillingPayment` хранит момент в Europe/Moscow; подписка перечитывается
  `refresh()` после блокировки; тариф проверяется до транзакции (EM остаётся
  открытым); `warning` на повтор с другим тарифом/сроком/суммой; тесты: UTC-момент
  после перечтения, устаревшая identity map, оплата в льготные дни, отключённый
  тариф, чужой `paymentId` → проброс `UniqueConstraintViolationException`.
- После правок: Billing unit + integration — 51 OK; unit-набор — 4315 OK;
  PHPStan — No errors; CS — чисто.
- В follow-up (TASK.md): `ON DELETE CASCADE` журнала оплат; подтверждение
  в админке при оплате, снимающей «Друга».