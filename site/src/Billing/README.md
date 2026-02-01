# Billing Module

# Billing module — логика работы (v1)

## 0) Назначение
Модуль **Billing** управляет:
- тарифами (**Plan**) и их возможностями (**Feature**) + лимитами (**PlanFeature**)
- подключаемыми платными/включенными модулями (**Integration**) и их включением в подписке (**SubscriptionIntegration**)
- подпиской компании (**Subscription**) и её статусами (trial/active/grace/suspended/…)
- агрегированным потреблением ресурсов (**UsageCounter**) для enforcement лимитов
- единым API доступа для остальных модулей через **AccessManagerInterface**

Ключевой принцип:
> **Тариф = фичи + лимиты**  
> **Интеграции = отдельные биллабл-модули**  
> **Доступ = policy + runtime проверки**, без if’ов по тарифам

---

## 1) Термины и объекты домена

### 1.1 Plan (тариф)
Тариф — это неизменяемая конфигурация:
- `code` — уникальный идентификатор версии (`starter_2026`, `pro_2026`)
- `billingPeriod` — период (month/year)
- `priceAmount`, `priceCurrency`
- `isActive`

**Правило immutable:** существующий `Plan` не меняется. Любая правка = новый код (`*_v2`, `*_2026`).

### 1.2 Feature (возможность)
Feature — атомарная возможность, которую можно включить/выключить или лимитировать.
- `code` — уникальный ключ (`cash.write`, `exports.xlsx`, `transactions.monthly`)
- `type` — `BOOLEAN | LIMIT | ENUM`
- `name`, `description`

### 1.3 PlanFeature (настройка Feature в Plan)
Связка тариф ↔ возможность:
- `plan + feature`
- `value` — универсальная строка (`"true"`, `"1000"`, `"advanced"`)
- `softLimit` / `hardLimit` — числовые ограничения (опционально)

> В v1: `value` остаётся строкой, без JSON, для простоты и стабильности.

### 1.4 Integration (интеграция/модуль)
Интеграция — подключаемый модуль канала/провайдера:
- `code`: `telegram`, `wb`, `bank_alpha`
- `billingType`: `INCLUDED | ADDON`
- `priceAmount/priceCurrency` (только для ADDON или при необходимости)
- `isActive`

`IntegrationBillingType`:
- `INCLUDED` — включено в тариф (не продаётся отдельно)
- `ADDON` — продаётся отдельно как аддон

### 1.5 Subscription (подписка компании)
Подписка существует **у компании** (multi-tenant):
- `company`
- `plan`
- `status` (enum)
- `trialEndsAt`
- `currentPeriodStart`, `currentPeriodEnd`
- `cancelAtPeriodEnd`

Статусы (`SubscriptionStatus`):
- `TRIAL` — тестовый период
- `ACTIVE` — оплаченная/активная подписка
- `GRACE` — льготный период (ограничения записи)
- `SUSPENDED` — заморозка (запрет записи, опционально чтение)
- `CANCELED` — отменено

### 1.6 SubscriptionIntegration (подключенные интеграции)
Фиксирует включение интеграции в подписке:
- `subscription`
- `integration`
- `status`: `ACTIVE | DISABLED`
- `startedAt`, `endedAt`

### 1.7 UsageCounter (агрегированное потребление)
Хранит usage по компании, метрике и периоду:
- `company`
- `periodKey` (YYYY-MM)
- `metric` (`transactions.created`, `ai.tokens`)
- `used` (int)

**Принцип:** usage агрегированный, не считаем на лету.

---

## 2) Границы ответственности (важно)

### 2.1 Модуль Billing НЕ делает
- не реализует оплату в v1
- не изменяет поведение существующих модулей автоматически
- не содержит бизнес-логики Cash/Loan/AI — только правила доступа и лимиты

### 2.2 Модуль Billing делает
- хранит конфигурацию тарифов/фич/интеграций
- вычисляет доступ
- хранит и обновляет usage counters
- предоставляет единый интерфейс (AccessManager) для остальных модулей

---

## 3) Технические правила реализации (паттерны проекта)

1) **Enum везде**, где есть перечисления  
2) **Doctrine только в репозиториях**  
   - сервисы/контроллеры не используют EntityManager/QueryBuilder напрямую  
3) **Контроллеры тонкие**  
   - CompanyContextService → Service → Response  
4) **Логика в сервисах**, явные зависимости через DI, без магии  
5) Репозитории возвращают типизированные сущности/DTO, не “массивы”

---

## 4) Жизненный цикл подписки

### 4.1 Создание подписки при создании компании
Триггер: `CompanyCreatedEvent`

Сервис: `SubscriptionManager::ensureTrialForCompany(Company $company)`

Алгоритм:
1. Если у компании уже есть подписка → выход (идемпотентность)
2. Найти `starter_2026` через `PlanRepository::findOneByCode()`
3. Если не найден → `PlanRepository::findFirstActive()`
4. Если план не найден → лог warning и выход без исключения
5. Создать `Subscription`:
   - status = `TRIAL`
   - trialEndsAt = now + 14 days
   - currentPeriodStart = now
   - currentPeriodEnd = now + 14 days
6. Сохранить через `SubscriptionRepository`

**Цель:** компания всегда имеет подписку (или система не падает).

---

## 5) Единый слой доступа: AccessManager

### 5.1 Роль AccessManager
`AccessManager` — единая точка принятия решения:
- доступ к фичам
- доступ к интеграциям
- лимиты и остатки
- соблюдение статуса подписки

Другие модули НЕ знают:
- что такое PlanFeature
- где лежат лимиты
- как хранится подписка

Они знают только:
```php
$access->can('pnl.view');
$access->denyUnlessCan('cash.write');
$access->integrationEnabled('telegram');
$access->limit('transactions.monthly')->canWrite();
````

### 5.2 Источники данных AccessManager (через репозитории)

* `SubscriptionRepository` — текущая подписка компании
* `PlanFeatureRepository` — фичи и лимиты тарифа
* `SubscriptionIntegrationRepository` — включенные интеграции
* `UsageCounterRepository` — использовано

### 5.3 can(permission)

`permission` — строка, соответствующая `Feature.code` (или маппингом).

Алгоритм:

1. Получить текущую подписку компании (BillingFacade/SubscriptionRepository)
2. Если подписки нет → false (или fallback policy)
3. Если статус `SUSPENDED` → false для write-пермиссий (в v1 можно трактовать строго)
4. Найти PlanFeature по `featureCode`
5. Если FeatureType=BOOLEAN:

    * value == "true" → true, иначе false
6. Если FeatureType=LIMIT или ENUM:

    * наличие значения → true (доступ есть, но ограничения ниже через limit())
7. Иначе → false

> В v1 можно считать, что `can()` проверяет наличие права, а `limit()` проверяет количественные ограничения.

### 5.4 denyUnlessCan(permission)

* если `can()==false` → бросить `AccessDeniedHttpException`
* используется сначала только в Billing UI/Admin, затем точечно подключается в бизнес-операции.

### 5.5 integrationEnabled(integrationCode)

Алгоритм:

1. Получить subscription компании
2. Если нет subscription → false
3. Найти Integration по code (в AccessManager можно не трогать IntegrationRepository, если SubscriptionIntegrationRepository умеет `isIntegrationEnabled`)
4. Вернуть true если есть активная запись `SubscriptionIntegration(status=ACTIVE)`
5. Дополнительно (опционально): если интеграция `billingType=INCLUDED`, можно считать включенной по умолчанию, но это решение фиксируется отдельно (в v1 можно требовать явного включения через SubscriptionIntegration).

### 5.6 limit(metric)

`metric` — строка бизнес-метрики (не обязательно равна feature.code).

Вводится явный маппинг `MetricMap`:

* `transactions.monthly` → feature `transactions.monthly`
* `ai.tokens` → feature `ai.tokens`

Алгоритм:

1. Определить `featureCode` через `MetricMap`
2. Получить subscription, plan
3. Получить лимиты из PlanFeature:

    * `softLimit`, `hardLimit` (или value, если лимит задаем value)
4. Получить usage:

    * `periodKey = YYYY-MM` для `now`
    * used = UsageCounterRepository->getUsed(...)
5. Собрать `LimitState`:

    * remaining = hardLimit - used (если hardLimit задан)
    * isSoftExceeded / isHardExceeded
    * canWrite() = hardLimit is null OR used < hardLimit

---

## 6) Usage: как учитываем потребление (UsageMeter)

### 6.1 Роль UsageMeter

Сервис, который увеличивает счетчики usage.

Важно:

* Сервис **не ходит в Doctrine напрямую**
* Вся работа с upsert инкапсулирована в `UsageCounterRepository::upsertIncrement()`

### 6.2 increment()

```php
$usageMeter->increment($company, 'transactions.created', 1);
```

Алгоритм:

1. periodKey = YYYY-MM (из `at` или now)
2. вызвать `UsageCounterRepository::upsertIncrement(company, periodKey, metric, by)`

### 6.3 Где вызывается UsageMeter

Встраивание постепенное:

* сначала только в новые не критичные эндпоинты
* потом — в операции создания транзакций / AI-run
* потом — в импорты (осторожно)

---

## 7) Enforcement: как включаем ограничения

### 7.1 Стратегия “не ломать прод”

Enforcement включается поэтапно:

1. сначала Billing UI/Admin (только отображение)
2. потом 1 безопасная проверка `billing.view`
3. потом 1 безопасный лимит на не критичном эндпоинте
4. потом — точечно в CashTransaction create и т.д.

### 7.2 Правила статусов

Рекомендуемые правила (v1):

* `TRIAL`: можно писать, но лимиты могут быть ниже
* `ACTIVE`: все права по плану
* `GRACE`: чтение да, запись ограниченно (часто write=false)
* `SUSPENDED`: чтение опционально, запись нет
* `CANCELED`: по политике (обычно как suspended)

> Конкретная матрица (что считать read/write) фиксируется отдельной таблицей правил.

---

## 8) Admin-часть Billing

### 8.1 Цели Admin

* просматривать тарифы, фичи, интеграции
* просматривать подписки компаний
* (позже) переключать план и интеграции, включать grace/suspend
* смотреть usage и лимиты

### 8.2 Архитектура Admin

Контроллеры Admin — тонкие, данные готовятся QueryService:

* `BillingDashboardController` (без DB)
* `PlanAdminController` → `PlanAdminQueryService`
* `FeatureAdminController` → `FeatureAdminQueryService`
* `IntegrationAdminController` → `IntegrationAdminQueryService`

В v1: только read-only, чтобы не ломать.

---

## 9) Company UI (Billing page)

### 9.1 Страница /company/billing

* показывает текущий план, статус, даты периода
* показывает таблицу лимитов по `MetricMap`
* показывает подключенные интеграции

Сбор данных выполняет:

* `CompanyBillingViewService` (view-model)
  контроллер только вызывает сервис и рендерит twig.

---

## 10) Логи и наблюдаемость (минимум)

* при отсутствии Plan в момент CompanyCreated → warning лог
* при невозможности определить subscription → debug
* при превышении hard-limit (когда включим enforcement) → info/warn с company_id, metric, used, limit

---

## 11) Ожидаемые сценарии (пример)

### Сценарий A: новая компания

1. Company создана
2. Listener создает `Subscription(TRIAL, starter_2026, 14 дней)`
3. Billing page показывает trial и лимиты

### Сценарий B: интеграция Telegram как ADDON

1. Admin включает `SubscriptionIntegration(telegram, ACTIVE)`
2. `integrationEnabled('telegram') == true`
3. Модуль Telegram разрешает подключение/использование, если проверка внедрена

### Сценарий C: лимит транзакций

1. PlanFeature: `transactions.monthly hardLimit=1000`
2. UsageCounter: used=999
3. limit('transactions.monthly')->canWrite() == true
4. После increment used=1000 → canWrite()==false

---

## 12) Точки расширения (после v1)

* оплаты/провайдеры (Stripe/ЮKassa/CloudPayments): подписка продлевается, меняется статус
* pro-rating при апгрейде
* downgrade на следующий период
* “included integrations by plan” (если решим)
* пакеты usage (buy more tokens)
* партнерские планы/скидки/промокоды
* аудит изменений (кто сменил план/интеграцию)

---

